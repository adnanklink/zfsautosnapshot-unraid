<?php
// Called only by the disposable real-pool fixture, with an already seeded receiver.
if (!is_file('/.dockerenv') || getenv('ZFSAS_DISPOSABLE_POOL_TEST') !== '1' || !in_array(count($argv),[4,5],true)) { exit(77); }
$plugin=__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php';
require $plugin.'/replication-submit.php';
require_once $plugin.'/coordinator-socket.php'; require_once $plugin.'/send-helpers.php'; require_once $plugin.'/replication-inspection.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($r){$v=zfsas_coordinator_request($r);check($v['ok'],json_encode($v));return $v['result'];}
function until($f){$deadline=microtime(true)+30;do{if($v=$f())return $v;usleep(30000);}while(microtime(true)<$deadline);throw new RuntimeException('Native fixture timed out: '.@file_get_contents('/tmp/snapsync-native-daemon.log'));}
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
file_put_contents($config.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"snapsync-send-\"\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
$read=fn($name)=>trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$name]));
$request=['action'=>'replication','commandId'=>'native-real-send-'.substr(hash('sha256',$argv[2]),0,12),'revision'=>zfsas_config_revision($config),
 'sourceDatasetGuid'=>$read($argv[1]),'replication'=>['sourceSnapshot'=>$argv[1].'@next','sourceGuid'=>$read($argv[1].'@next'),
 'destination'=>$argv[2]]];
if (($argv[4]??'')==='full') { $request['replication'] += ['createDestination'=>true,'destinationParentGuid'=>$read(substr($argv[2],0,strrpos($argv[2],'/')))]; }
else { $request['replication']['destinationGuid']=$read($argv[2]); }
$daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/snapsync-native-daemon.log','a'],2=>['file','/tmp/snapsync-native-daemon.log','a']],$pipes);
try{
 until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 $receipt=rpc($request);check(rpc($request)===$receipt,'Duplicate submission changed operation');
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId'] && ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete',json_encode($run));
 check(zfsas_native_manual_send($request['replication']['sourceSnapshot'],$request['replication']['sourceGuid'],$argv[2],$request['commandId'])===$receipt,'UI retry after receiver creation duplicated or rejected the accepted command');
 check(count($run['taskStatus'])===4,'Native graph omitted a phase');
 foreach($run['taskStatus'] as $task){check($task['state']==='complete' && $task['result']['outcome']==='success','Child completion lacks explicit success');}
 check($read($argv[2].'@next')===$request['replication']['sourceGuid'],'Native transfer checkpoint differs');
 check($read($argv[3])!=='' ,'Unrelated receiver snapshot disappeared');
 $request['commandId'].='-already';$receipt=rpc($request);
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId'] && ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete' && count($run['taskStatus'])===2,'Completed target was retransferred instead of verified');
 file_put_contents('/var/local/emhttp/var.ini','mdState="STOPPED"');
 $request['commandId'].='-cancel';
 $receipt=rpc($request);
 until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId'] && in_array('array',$run['blockedReasons'],true))return $run;}return false;});
 $cancel=rpc(['action'=>'cancel','runId'=>$receipt['runId']]);check($cancel['cancellationCommitted'],'Native cancellation not committed');
 until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId'] && $run['state']==='canceled')return $run;}return false;});
 check(!rpc(['action'=>'status'])['autoPaused'],'Manual native cancel paused Auto Snapshot');
 file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
 echo "PASS: actual coordinator native incremental graph, reference publication, measured space, explicit finalization, duplicate submission and GUID-proven no-op\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);}
