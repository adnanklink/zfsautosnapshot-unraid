<?php
if(!is_file('/.dockerenv')||getenv('ZFSAS_DISPOSABLE_POOL_TEST')!=='1'||count($argv)!==3){exit(77);}
$plugin=__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php';
require $plugin.'/coordinator-socket.php';require $plugin.'/replication-inspection.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($request){$response=zfsas_coordinator_request($request);check($response['ok'],json_encode($response));return $response['result'];}
function until($fn){$end=microtime(true)+45;do{if($result=$fn())return $result;usleep(30000);}while(microtime(true)<$end);throw new RuntimeException('Scheduled fixture timeout: '.@file_get_contents('/tmp/snapsync-schedule-daemon.log'));}
// Isolated RAM mount: production daemon/workers must tolerate read-only flash.
exec('/bin/mount -t tmpfs -o size=8m tmpfs /boot',$mountOutput,$mountCode);check($mountCode===0,'Cannot create isolated flash fixture');
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
$id='abcdef123456';
$anchors=getenv('ZFSAS_TEST_ANCHOR')==='1';
$daily=$anchors?'1':'0';$weekly=$anchors?'2':'0';
$policies=json_encode(['version'=>1,'jobs'=>(object)($anchors?[$id=>'older_anchors']:[])]);
file_put_contents($config.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"snapsync-send-\"\nSEND_KEEP_ALL_FOR_DAYS=\"0\"\nSEND_KEEP_DAILY_UNTIL_DAYS=\"$daily\"\nSEND_KEEP_WEEKLY_UNTIL_DAYS=\"$weekly\"\nSEND_CLEANUP_POLICIES='$policies'\nSEND_JOBS=\"$id|{$argv[1]}|{$argv[2]}|1d|0G|1|local\"\n");
// This fixture submits an explicit occurrence; keep the autonomous timer in the future.
file_put_contents($config.'/zfs_send.conf', "SEND_SCHEDULE_SPECS='".json_encode(['abcdef123456'=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]])."'\n", FILE_APPEND);
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
exec('/bin/mount -o remount,ro /boot',$mountOutput,$mountCode);check($mountCode===0,'Cannot protect flash fixture');
$daemonCommand=[PHP_BINARY,$plugin.'/coordinator-daemon.php'];
if (getenv('ZFSAS_TRACE_ANCHOR')==='1') {
 $daemonCommand=array_merge(['/trace-tools/ld-linux-x86-64.so.2','--library-path','/trace-tools','/trace-tools/strace','-D','-f','-yy','-s','512','-e','trace=%file','-o','/trace-output/anchor-files.log'],$daemonCommand);
}
$daemon=proc_open($daemonCommand,[1=>['file','/tmp/snapsync-schedule-daemon.log','a'],2=>['file','/tmp/snapsync-schedule-daemon.log','a']],$pipes);
try{
 until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 $request=['action'=>'scheduled_replication','scheduleId'=>$id,'commandId'=>'recursive-fixture','occurrence'=>100];
 $receipt=rpc($request);check(rpc($request)===$receipt,'Repeated schedule request duplicated run');
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete',json_encode($run));
 $inventory=ZfsasReplicationInspection::command(['list','-H','-o','name','-t','snapshot','-d','1','--',$argv[2]]);
 check(!str_contains($inventory,'@snapsync-send-'.$id.'-old'),'Prerequisite retention did not remove eligible old checkpoint');
 check(str_contains($inventory,'@snapsync-send-'.$id.'-base'),'Prerequisite retention removed incremental base');
 foreach(['','/child','/child/deep'] as $suffix){
  $name='@snapsync-send-'.$id.'-100';
  $source=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$argv[1].$suffix.$name]));
  $target=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$argv[2].$suffix.$name]));
  check($source===$target,'Recursive child GUID differs');
 }
 $cleanupTasks=array_filter($run['taskStatus'],fn($task)=>$task['kind']==='delete');
 if ($anchors) { check(str_contains(json_encode($run['taskStatus']),':pressure-'),'Retained anchor was not deleted through the pressure gate'); }
 check(count($cleanupTasks)===1,'Native graph omitted prerequisite retention');
 $cleanupId=reset($cleanupTasks)['id'];$spaceTask=null;
 foreach($run['taskStatus'] as $task){if(str_ends_with($task['id'],':member-00000:space'))$spaceTask=$task;}
 check($spaceTask && in_array($cleanupId,$spaceTask['dependencies'],true),'Space approval bypassed prerequisite cleanup');
 check($spaceTask['result']['availableBytes'] >= $spaceTask['result']['requiredBytes'],'Space was approved using predicted reclaim');
 foreach($run['taskStatus'] as $task){check($task['state']==='complete'&&($task['result']['outcome']??'')==='success','Expected child lacks explicit success');}
 // A second command for the same intent adopts only metadata-proven snapshots.
 $request['commandId']='recursive-fixture-replay';$receipt=rpc($request);
 $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run){if($run['id']===$receipt['runId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;}return false;});
 check($run['state']==='complete',json_encode($run));
 echo "PASS: actual coordinator recursive schedule graph, fixed identities, quota shortage resolved by prerequisite retention, measured space, child completion barriers and metadata-proven replay\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);exec('/bin/umount /boot',$mountOutput,$mountCode);check($mountCode===0,'Cannot release flash fixture');}
