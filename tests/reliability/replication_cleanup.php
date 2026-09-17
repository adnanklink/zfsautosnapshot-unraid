<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-cleanup.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Unsafe cleanup accepted');}
$request=['sourceSnapshot'=>'tank/source@next','sourceGuid'=>'100','destination'=>'backup/data'];
$policy=['scheduleId'=>'abcdef123456','prefix'=>'snapsync-send-','sendConfigHash'=>str_repeat('a',64),'keepAll'=>14,'keepDaily'=>30,'keepWeekly'=>183];
$prefix='backup/data@snapsync-send-abcdef123456-';
$reference=['snapshot'=>$prefix.'base','guid'=>'200'];
$inspection=['mode'=>'incremental','destinationDatasetGuid'=>'20','references'=>[$reference]];
$rows=$prefix."newest\t300\t9\t1\t1\t0\t-\n".$prefix."base\t200\t8\t1\t1\t0\t-\n".$prefix."old\t190\t7\t1\t0\t0\t-\n".$prefix."held\t180\t6\t1\t1\t1\t-\n".$prefix."clone\t170\t5\t1\t1\t0\tbackup/clone\n"."backup/data@unrelated\t160\t4\t1\t1\t0\t-\n";
$read=static fn($args)=>$args[0]==='get'?'20':$rows;
$tasks=zfsas_replication_cleanup($request,$inspection,$policy,$read,20000000);
check(count($tasks)===1,'Cleanup included protected, held, cloned, newest or unrelated snapshot');
$job=reset($tasks)['parameters']['deleteJob'];
check($job['SNAPSHOT']===$prefix.'old'&&$job['DATASET_GUID']==='20'&&$job['SNAPSHOT_GUID']==='190','Cleanup lost exact receiver identity');
check($job['ESTIMATED_RECLAIM_BYTES']==='0','Zero-used chain leader incorrectly excluded');
$other=$inspection;$other['references'][]=['snapshot'=>'another/dataset@base','guid'=>'190'];
check(!zfsas_replication_cleanup($request,$other,$policy,$read,20000000),'Shared GUID reference not protected');
reject(fn()=>zfsas_replication_cleanup($request,$inspection,$policy,fn($args)=>$args[0]==='get'?'21':$rows,20000000));
reject(fn()=>zfsas_replication_cleanup($request,$inspection,$policy,fn($args)=>$args[0]==='get'?'20':str_replace("\t0\t-","\t-\t-",$rows),20000000));
check(zfsas_replication_cleanup($request,['mode'=>'full'],$policy,$read)===[],'New receiver invented cleanup targets');
echo "PASS: native retention exact scope, newest/base/shared GUID protection, holds/clones, zero-used candidates and receiver replacement rejection\n";
