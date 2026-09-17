<?php
// Endpoints and background workers use their real paths inside a disposable container.
if (!file_exists('/.dockerenv')) { throw new RuntimeException('Use the disposable test container.'); }
$base = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $base . '/snapshot-manager-helpers.php';
require_once $base . '/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$dir = '/boot/config/plugins/zfs.snapsync'; @mkdir($dir, 0775, true);
file_put_contents($dir . '/zfs_snapsync.conf', "PREFIX=\"auto-\"\nDATASETS=\"tank/data:10G\"\n");
file_put_contents($dir . '/zfs_send.conf', "SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/tmp/batch-fixture/bin', 0775, true);
$rows = [];
for ($i = 0; $i < 601; $i++) { $name = 'tank/data@auto-' . sprintf('%05d', $i); $rows[$name] = [$name, time()-$i*86400, 0, $i === 600 ? 100 : 0, 0, (string)($i+1), (string)($i+1), '-']; }
file_put_contents('/tmp/batch-fixture/rows.json', json_encode($rows));
file_put_contents('/tmp/batch-fixture/fail', 'tank/data@auto-00017');
file_put_contents('/tmp/batch-fixture/bin/zfs', <<<'PY'
#!/usr/bin/python3
import json, sys, os, fcntl
root='/tmp/batch-fixture/'
with open(root+'lock','a') as lock:
 fcntl.flock(lock, fcntl.LOCK_EX)
 rows=json.load(open(root+'rows.json')); args=sys.argv[1:]; target=args[-1]
 if args[0]=='list':
  for name,row in rows.items():
   if name==target or name.startswith(target+'@'): print('\t'.join(map(str,row)))
 elif args[0]=='holds':
  for name in args[1:]:
   if name in rows and rows[name][4]: print(name+'\tsnapsync-manual\tdate')
 elif args[0] in ('hold','release','destroy'):
  if os.path.exists(root+'fail') and open(root+'fail').read()==target: sys.exit('injected failure')
  if target not in rows: sys.exit('missing snapshot')
  with open(root+'actions','a') as actions: actions.write(args[0]+' '+target+'\n')
  if args[0]=='destroy': del rows[target]
  else: rows[target][4]=int(args[0]=='hold')
  with open(root+'rows.json','w') as output: json.dump(rows,output)
 elif args[0]=='get':
  if target not in rows: sys.exit(1)
  props=args[args.index('value')+1]
  for prop in props.split(','): print({'guid':rows[target][5],'userrefs':rows[target][4],'clones':rows[target][7]}.get(prop,'-'))
 else: sys.exit('unsupported mock command: '+repr(args))
PY);
chmod('/tmp/batch-fixture/bin/zfs', 0755); putenv('PATH=/tmp/batch-fixture/bin:' . getenv('PATH'));
$runner = '/tmp/batch-fixture/endpoint.php';
file_put_contents($runner, '<?php $GLOBALS["csrf_token"]="fixture"; $_SERVER["REQUEST_METHOD"]="POST"; $_POST=json_decode(base64_decode($argv[2]),true); require $argv[1];');
function endpoint($data) {
    global $base, $runner;
    $data += ['csrf_token'=>'fixture']; $pipes=[];
    $proc=proc_open([PHP_BINARY,$runner,$base.'/snapshot-manager-batch.php',base64_encode(json_encode($data))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
    check((bool)preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$out,$m),'Bad response: '.$out.$err);
    return json_decode($m[1],true);
}
function capture($action,$items) {
    $token='';
    foreach(array_chunk($items,500) as $index=>$chunk) {
        $r=endpoint(['action'=>'capture','operation'=>$action,'dataset'=>'tank/data','token'=>$token,'items'=>json_encode($chunk),'seal'=>($index+1)*500>=count($items)?'1':'0']);
        check($r['ok'], 'Capture failed: '.json_encode($r));$token=$r['token'];
    }
    return $r;
}
function wait_batch($token) {
    $until=microtime(true)+420;
    do { usleep(100000); $r=endpoint(['action'=>'status','token'=>$token]); check($r['ok'],'Status failed'); if ($r['state']==='complete') return $r; } while(microtime(true)<$until);
    throw new RuntimeException('Batch did not complete: '.json_encode($r).' '.@file_get_contents('/var/log/zfs_snapsync_snapshot_manager.log'));
}
$items=array_map(fn($r)=>['snapshot'=>$r[0],'guid'=>$r[5]],array_values($rows));
$over=endpoint(['action'=>'capture','operation'=>'hold','dataset'=>'tank/data','items'=>json_encode($items),'seal'=>'1']);check(!$over['ok'],'Explicit request limit bypassed');
$r=capture('hold',$items);check($r['eligible']===601,'Large review omitted items');
check(!file_exists('/tmp/batch-fixture/actions'),'Review mutated snapshots');
$token=$r['token'];
$submitted=endpoint(['action'=>'submit','token'=>$token]); check($submitted['ok'] && !empty($submitted['runId']),'Submit failed');
check(endpoint(['action'=>'submit','token'=>$token])['runId']===$submitted['runId'],'Duplicate submit lost run identity');
$r=wait_batch($token);check($r['counts']['completed']===600 && $r['counts']['failed']===1,'Partial failure accounting: '.json_encode($r['counts']));
check(count(file('/tmp/batch-fixture/actions'))===600,'Duplicate actions repeated successes');
// Polls must not even republish an unchanged manifest or create lock files.
$path=zfsas_sm_batch_path($token); $before=file_get_contents($path); $stat=stat($path);
for($poll=0;$poll<3;$poll++) { check(endpoint(['action'=>'status','token'=>$token])['ok'],'Read-only poll failed'); }
clearstatcache(true,$path); check(file_get_contents($path)===$before && stat($path)['ino']===$stat['ino'],'Polling republished runtime state');
$journal=ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator'); $taskId=$journal['runs'][$submitted['runId']]['tasks'][0];
$attempts=array_filter($journal['attempts'],fn($attempt)=>$attempt['taskId']===$taskId);
check(count($attempts)>=13,'Coordinator did not bound 601-item work into chunks of 50');
unlink('/tmp/batch-fixture/fail');
$retry=endpoint(['action'=>'retry','token'=>$token]);check($retry['selected']===1 && $retry['eligible']===1,'Retry included successes');
check(endpoint(['action'=>'submit','token'=>$retry['token']])['ok'],'Retry submit failed');
check(wait_batch($retry['token'])['counts']['completed']===1,'Retry did not complete');
check(count(file('/tmp/batch-fixture/actions'))===601,'Retry duplicated successes');
// Exact identity revalidation after approval: replacements must not receive an action.
$r=capture('release',[$items[1]]);$live=json_decode(file_get_contents('/tmp/batch-fixture/rows.json'),true);$live[$items[1]['snapshot']][5]='999999';file_put_contents('/tmp/batch-fixture/rows.json',json_encode($live));
endpoint(['action'=>'submit','token'=>$r['token']]);check(wait_batch($r['token'])['counts']['skipped']===1,'Changed GUID was acted upon');
$r=capture('release',[$items[0]]);$batch=zfsas_sm_read_json_file(zfsas_sm_batch_path($r['token']));$batch['expires']=time()-1;zfsas_sm_batch_store($batch);
check(!endpoint(['action'=>'submit','token'=>$r['token']])['ok'],'Expired preview accepted');
// Cleanup holds remain explained and no preview executes ZFS mutation.
$before=count(file('/tmp/batch-fixture/actions'));
$r=endpoint(['action'=>'cleanup','dataset'=>'tank/data','mode'=>'zero_change']);check($r['ok'] && $r['eligible']===0,'Cleanup offered held snapshots');
check(count(file('/tmp/batch-fixture/actions'))===$before,'Cleanup preview mutated snapshots');
// Run the real shared delete daemon too; only fake ZFS receives mutations.
@mkdir('/var/local/emhttp',0775,true);file_put_contents('/var/local/emhttp/var.ini', 'mdState="STARTED"');
$release=capture('release',[$items[3],$items[4],$items[5]]);endpoint(['action'=>'submit','token'=>$release['token']]);check(wait_batch($release['token'])['counts']['completed']===3,'Release fixture failed');
$delete=capture('delete',[$items[3],$items[4]]);check($delete['eligible']===2,'Delete review excluded unheld snapshots');
endpoint(['action'=>'submit','token'=>$delete['token']]);endpoint(['action'=>'submit','token'=>$delete['token']]);
$deleted=wait_batch($delete['token']);check($deleted['counts']['completed']===2,'Delete result accounting: '.json_encode($deleted));
$live=json_decode(file_get_contents('/tmp/batch-fixture/rows.json'),true);
check(!isset($live[$items[3]['snapshot']]) && !isset($live[$items[4]['snapshot']]) && isset($live[$items[5]['snapshot']]),'Delete expanded beyond exact selection');
$destroy=array_values(array_filter(file('/tmp/batch-fixture/actions'),fn($line)=>str_starts_with($line,'destroy ')));check(count($destroy)===2,'Duplicate destroy executed');
// Publication after an idle daemon exit must launch a successor, and delete
// failures must flow back through the shared result journal into failed-only retry.
usleep(2200000);
file_put_contents('/tmp/batch-fixture/fail', $items[5]['snapshot']);
$failedDelete=capture('delete',[$items[5]]);endpoint(['action'=>'submit','token'=>$failedDelete['token']]);
$failedResult=wait_batch($failedDelete['token']);check($failedResult['counts']['failed']===1,'Delete failure not reported');
unlink('/tmp/batch-fixture/fail');
$retry=endpoint(['action'=>'retry','token'=>$failedDelete['token']]);check($retry['selected']===1 && $retry['eligible']===1,'Failed delete was not eligible for retry');
endpoint(['action'=>'submit','token'=>$retry['token']]);check(wait_batch($retry['token'])['counts']['completed']===1,'Failed delete retry did not recover');
echo "PASS: actual batch endpoints, 601-item manifests, 500-item limit, duplicate submissions, partial failures, failed-only retry, changed GUID, expired approval, held cleanup, exact deletion, daemon restart and deletion retry\n";
