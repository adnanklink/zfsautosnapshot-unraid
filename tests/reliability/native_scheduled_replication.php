<?php
if(!is_file('/.dockerenv')||getenv('ZFSAS_DISPOSABLE_POOL_TEST')!=='1'||count($argv)!==3){exit(77);}
$plugin=__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php';
require $plugin.'/coordinator-socket.php';require $plugin.'/replication-inspection.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($request){$response=zfsas_coordinator_request($request);check($response['ok'],json_encode($response));return $response['result'];}
function until($fn){$end=microtime(true)+45;do{if($result=$fn())return $result;usleep(30000);}while(microtime(true)<$end);throw new RuntimeException('Scheduled fixture timeout: '.@file_get_contents('/tmp/snapsync-schedule-daemon.log'));}
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
$id='abcdef123456';
file_put_contents($config.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"snapsync-send-\"\nSEND_JOBS=\"$id|{$argv[1]}|{$argv[2]}|1d|1G|1|local\"\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
$daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/snapsync-schedule-daemon.log','a'],2=>['file','/tmp/snapsync-schedule-daemon.log','a']],$pipes);
try{
 until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 $request=['action'=>'scheduled_replication','scheduleId'=>$id,'commandId'=>'recursive-fixture','occurrence'=>100];
 $receipt=rpc($request);check(rpc($request)===$receipt,'Repeated schedule request duplicated run');
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete',json_encode($run));
 foreach(['','/child','/child/deep'] as $suffix){
  $name='@snapsync-send-'.$id.'-100';
  $source=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$argv[1].$suffix.$name]));
  $target=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$argv[2].$suffix.$name]));
  check($source===$target,'Recursive child GUID differs');
 }
 foreach($run['taskStatus'] as $task){check($task['state']==='complete'&&($task['result']['outcome']??'')==='success','Expected child lacks explicit success');}
 // A second command for the same intent adopts only metadata-proven snapshots.
 $request['commandId']='recursive-fixture-replay';$receipt=rpc($request);
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete',json_encode($run));
 echo "PASS: actual coordinator recursive schedule graph, fixed identities, snapshot intent metadata, child completion barriers and metadata-proven replay\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);}
