<?php
if(!is_file('/.dockerenv')){exit(77);}
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin.'/snapshot-manager-helpers.php';require $plugin.'/coordinator-socket.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rpc($r){return zfsas_coordinator_request($r);}
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=\"\"\n");
file_put_contents($config.'/zfs_send.conf',"SEND_JOBS=\"abcdef123456|tank/data|backup/data|1d|0G|0|local\"\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STOPPED"');
$daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/schedule-cancel.log','a'],2=>['file','/tmp/schedule-cancel.log','a']],$pipes);
try{
 for($i=0;$i<100;$i++){try{if(rpc(['action'=>'status'])['ok'])break;}catch(Throwable $e){}usleep(50000);}
 $r=rpc(['action'=>'scheduled_replication','scheduleId'=>'abcdef123456','commandId'=>'cancel-fixture','occurrence'=>100]);check($r['ok'],json_encode($r));$id=$r['result']['runId'];
 $pause=zfsas_ops_control_path('paused','abcdef123456');$cancel=zfsas_ops_control_path('cancelled',$id);
 @mkdir(dirname($pause),0770,true);mkdir($pause);
 check(!rpc(['action'=>'cancel','runId'=>$id])['ok'],'Failed pause acknowledged cancellation');
 check(!file_exists($cancel),'Cancellation persisted before required pause');rmdir($pause);
 @mkdir(dirname($cancel),0770,true);mkdir($cancel);
 check(!rpc(['action'=>'cancel','runId'=>$id])['ok'],'Failed cancellation publication acknowledged');
 check(is_file($pause),'Partial publication did not preserve schedule pause');rmdir($cancel);
 check(rpc(['action'=>'cancel','runId'=>$id])['ok'],'Cancellation retry failed');
 for($i=0;$i<100;$i++){$state=ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');if($state['runs'][$id]['state']==='canceled')break;usleep(50000);}
 check($state['runs'][$id]['state']==='canceled','Shutdown not verified');
 check(!is_file(zfsas_ops_control_path('paused','auto')),'Send cancellation paused Auto Snapshot');
 $r=rpc(['action'=>'resume','scheduleId'=>'abcdef123456']);check($r['ok']&&!is_file($pause),'Resume cleared wrong schedule');
 check(is_file($cancel),'Resume erased run cancellation evidence');
 check(!rpc(['action'=>'resume','scheduleId'=>'../invalid'])['ok'],'Invalid schedule accepted');
 echo "PASS: schedule pause precedes cancellation, partial-write retry, verified shutdown, targeted Resume and retained run decision\n";
}finally{proc_terminate($daemon,9);proc_close($daemon);}
