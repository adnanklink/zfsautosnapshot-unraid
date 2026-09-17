<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Use a disposable container with plugin and sbin mounts.'); }
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
require $plugin.'/php/coordinator-executor.php';require $plugin.'/php/coordinator-socket.php';
require $plugin.'/php/send-queue-helpers.php';
$root='/tmp/zfsas-delete-adapter';$socket='/var/run/zfs-autosnapshot-coordinator/control.sock';
if(($argv[1]??'')==='server'){
    $journal=new ZfsasCoordinatorState($root);
    $executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',
        fn($task)=>['/bin/bash',$plugin.'/scripts/coordinator-delete-attempt.sh',$task['parameters']['path']],
        fn($task,$code)=>['outcome'=>'validation_failure','message'=>'Adapter did not report an explicit result']);
    $server=new ZfsasCoordinatorSocket($socket,function($request)use($journal,$executor){
        return match($request['action']){
            'submit'=>$journal->submit($request['commandId'],$request['spec'],time()),
            'worker_report'=>$executor->workerReport($request),
            'status'=>$journal->state,
            'cancel'=>(function()use($executor,$request){$executor->cancel($request['runId']);return [];})(),
            default=>throw new InvalidArgumentException('Unknown action'),
        };
    },fn($now)=>$executor->tick($now));$server->serve();exit;
}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($request){$r=zfsas_coordinator_request($request);check($r['ok'],json_encode($r));return $r['result'];}
function until($fn){$deadline=microtime(true)+8;do{if($fn())return;usleep(20000);}while(microtime(true)<$deadline);throw new RuntimeException('Timeout: '.@file_get_contents('/tmp/zfsas-delete-adapter/server.log'));}
mkdir($root,0775,true);mkdir($root.'/bin');
$config='/boot/config/plugins/zfs.autosnapshot';mkdir($config,0775,true);
file_put_contents($config.'/zfs_autosnapshot.conf',"PREFIX=\"auto-\"\n");file_put_contents($config.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/var/local/emhttp',0775,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
file_put_contents($root.'/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,os
args=sys.argv[1:];target=args[-1];root='/tmp/zfsas-delete-adapter/'
if args[0]=='get':
 props=args[args.index('value')+1]
 for prop in props.split(','): print({'guid':'999' if target.endswith('changed') else '123','userrefs':'1' if target.endswith('held') else '0','clones':'-'}.get(prop,'-'))
elif args[0]=='destroy':
 with open(root+'destroy','a') as out:out.write(target+'\n')
 if target.endswith('failure'):sys.exit(1)
elif args[0]=='list':sys.exit(0)
else:sys.exit(1)
PY);
chmod($root.'/bin/zfs',0755);putenv('PATH='.$root.'/bin:'.getenv('PATH'));
$hash=trim(shell_exec('bash -c '.escapeshellarg('source '.$plugin.'/scripts/ops-queue-lib.sh; send_config_hash')));
check((bool)preg_match('/^[a-f0-9]+$/',$hash),'No captured config hash');
$inputs='/tmp/zfs-autosnapshot-coordinator/attempt-inputs';mkdir($inputs,0775,true);
$proc=proc_open([PHP_BINARY,__FILE__,'server'],[0=>['file','/dev/null','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
try{
    until(function(){try{rpc(['action'=>'status']);return true;}catch(Throwable $e){return false;}});
    foreach(['normal','held','changed','failure'] as $case){
        $id='delete-'.$case;$path=$inputs.'/'.$id.'.job';
        check(zfsas_ops_write_job_file($path,['JOB_ID'=>$id,'DATASET'=>'tank/data','SNAPSHOT'=>'tank/data@auto-'.$case,'SNAPSHOT_GUID'=>'123','SEND_CONFIG_HASH'=>$hash,'SEND_PROTECTED'=>'0','DELETE_SCOPE'=>'snapshot']),'Job capture failed');
        if($case==='normal'){
            @mkdir(zfsas_ops_status_dir().'/delete-results',0775,true);
            file_put_contents(zfsas_ops_status_dir().'/delete-results/'.$id.'.result', "skipped\tStale compatibility projection\n");
        }
        $receipt=rpc(['action'=>'submit','commandId'=>$id,'spec'=>['tasks'=>['delete'=>['kind'=>'delete','dataset'=>'tank/data','parameters'=>['path'=>$path]]]]]);$task=$receipt['runId'].':delete';
        until(function()use($task,$case){$state=rpc(['action'=>'status']);return $state['tasks'][$task]['state']===($case==='failure'?'retry_wait':'complete');});
        $state=rpc(['action'=>'status']);$result=$state['tasks'][$task]['result'];
        check($result['itemState']===($case==='normal'?'completed':($case==='failure'?'failed':'skipped')),'Incorrect explicit deletion outcome');
        if($case==='normal'){
            check(file_get_contents(zfsas_ops_status_dir().'/delete-results/'.$id.'.result')==="skipped\tStale compatibility projection\n",'Worker rewrote compatibility evidence');
        }else{check(!is_file(zfsas_ops_status_dir().'/delete-results/'.$id.'.result'),'Worker published authoritative result file');}
        if($case==='failure'){check($state['tasks'][$task]['attemptCount']===1,'Worker retried internally');rpc(['action'=>'cancel','runId'=>$receipt['runId']]);}
    }
    check(file($root.'/destroy',FILE_IGNORE_NEW_LINES)===['tank/data@auto-normal','tank/data@auto-failure'],'Unsafe or repeated destroy');
    echo "PASS: granted single deletion adapter, exact GUID and hold checks, explicit outcomes, no worker queue publication, coordinator-owned retry\n";
}finally{proc_terminate($proc,9);proc_close($proc);}
