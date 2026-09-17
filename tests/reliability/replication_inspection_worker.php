<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires isolated container.'); }
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php');
require $plugin.'/coordinator-executor.php';require $plugin.'/coordinator-socket.php';require $plugin.'/coordinator-replication-inspect.php';
$root='/tmp/zfs-autosnapshot-coordinator';
if(($argv[1]??'')==='server'){
    $j=new ZfsasCoordinatorState($root);
    $e=new ZfsasCoordinatorExecutor($j,$root,$root.'/runtime',fn($task)=>zfsas_coordinator_replication_inspection_command($task,$root),fn()=>['outcome'=>'transient_failure']);
    $s=new ZfsasCoordinatorSocket('/var/run/zfs-autosnapshot-coordinator/control.sock',function($r)use($j,$e){return match($r['action']){
        'submit'=>$j->submit($r['commandId'],$r['spec'],time()),'status'=>$j->state,'worker_report'=>$e->workerReport($r),
        'cancel'=>(function()use($r,$e){$e->cancel($r['runId']);return [];})(),default=>throw new InvalidArgumentException('Unknown request')};},fn($now)=>$e->tick($now));
    $s->serve();exit;
}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($r){$v=zfsas_coordinator_request($r);check($v['ok'],json_encode($v));return $v['result'];}
function until($fn,$seconds=8){$end=microtime(true)+$seconds;do{if($fn())return;usleep(20000);}while(microtime(true)<$end);throw new RuntimeException('Fixture timed out: '.@file_get_contents('/tmp/inspection-fixture/server.log'));}
$fixture='/tmp/inspection-fixture';mkdir($fixture);
file_put_contents($fixture.'/zfs', <<<'PY'
#!/usr/bin/python3
import sys,time,os
args=sys.argv[1:];name=args[-1]
with open('/tmp/inspection-fixture/actions','a') as f:f.write(' '.join(args)+'\n')
if name=='backup/slow':
 with open('/tmp/inspection-fixture/slow-pid','w') as f:f.write(str(os.getpid()))
 time.sleep(60)
if args[0]=='list':
 if name=='tank/source':print('tank/source@new\t300\t3')
elif args[0]=='get':
 if 'receive_resume_token' in args:print('-')
 else:print({'tank/source':'10','tank/source@new':'300','backup/target':'20','backup/slow':'21'}[name])
else:sys.exit(1)
PY);
chmod($fixture.'/zfs',0755);putenv('PATH='.$fixture.':'.getenv('PATH'));
$server=proc_open([PHP_BINARY,__FILE__,'server'],[1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes);
try{
 until(function(){try{return(bool)rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 $spec=fn($dest)=>['tasks'=>['inspect'=>['kind'=>'prepare','parameters'=>['phase'=>'replication_inspect','replication'=>['sourceSnapshot'=>'tank/source@new','sourceGuid'=>'300','destination'=>$dest]]]]];
 $run=rpc(['action'=>'submit','commandId'=>'normal','spec'=>$spec('backup/target')])['runId'];
 until(fn()=>(rpc(['action'=>'status'])['runs'][$run]['state']??'')==='complete');
 $state=rpc(['action'=>'status']);check($state['tasks'][$run.':inspect']['result']['inspection']['sourceGuid']==='300','Explicit inspection outcome missing');
 $slow=rpc(['action'=>'submit','commandId'=>'slow','spec'=>$spec('backup/slow')])['runId'];
 until(fn()=>is_file($fixture.'/slow-pid'));
 $start=microtime(true);rpc(['action'=>'status']);check(microtime(true)-$start<1,'Slow inspection blocked socket');
 $state=rpc(['action'=>'status']);$attempt=$state['attempts'][$state['tasks'][$slow.':inspect']['attempt']];
 $pid=(int)file_get_contents($fixture.'/slow-pid');$child=ZfsasCoordinatorExecutor::identity($pid);
 check($child['group']===$attempt['pid'],'Inspection escaped granted process group');
 rpc(['action'=>'cancel','runId'=>$slow]);until(fn()=>(rpc(['action'=>'status'])['runs'][$slow]['state']??'')==='canceled');
 check(ZfsasCoordinatorExecutor::members($attempt['pid'],$attempt['start'])===[],'Cancel left inspection children');
 $timeout=rpc(['action'=>'submit','commandId'=>'timeout','spec'=>$spec('backup/slow')])['runId'];
 until(fn()=>(rpc(['action'=>'status'])['tasks'][$timeout.':inspect']['state']??'')==='retry_wait',22);
 $state=rpc(['action'=>'status']);check($state['tasks'][$timeout.':inspect']['attemptCount']===1,'Timeout retry not owned by coordinator');
 check(str_contains($state['tasks'][$timeout.':inspect']['result']['message'],'timed out'),'Timeout outcome lost');
 rpc(['action'=>'cancel','runId'=>$timeout]);
 foreach(file($fixture.'/actions') as $line){check(preg_match('/^(get|list) /',$line),'Mutation executed during inspection');}
 echo "PASS: granted replication inspection, responsive socket, process-group cancellation, bounded timeout and coordinator retry\n";
}finally{proc_terminate($server,9);proc_close($server);}
