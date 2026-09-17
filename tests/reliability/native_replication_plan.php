<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-replication.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe request accepted');}
$j=new ZfsasCoordinatorState('/tmp/native-plan-'.bin2hex(random_bytes(8)));
$revision=str_repeat('a',64);
$request=['commandId'=>'native','revision'=>$revision,'sourceDatasetGuid'=>'10','replication'=>[
 'sourceSnapshot'=>'tank/source@next','sourceGuid'=>'300','destination'=>'backup/target','destinationGuid'=>'20']];
$receipt=zfsas_coordinator_submit_replication($j,$request,$revision,['SEND_RATE_LIMIT'=>'20M']);
check(zfsas_coordinator_submit_replication($j,$request,str_repeat('b',64),['SEND_RATE_LIMIT'=>'30M'])===$receipt,'Duplicate submission lost captured settings');
check($j->deletionReferenceOwners('tank/source@next','300')===[$receipt['runId']],'Selected manual snapshot unprotected before planning');
reject(fn()=>zfsas_coordinator_submit_replication($j,array_replace($request,['sourceDatasetGuid'=>'11']),$revision));
$id=$receipt['runId'].':prepare';$token=$j->claim($id,1,1,'generation');$j->started($id,$token,123,'123');
$source=['role'=>'source','endpoint'=>'local','dataset'=>'tank/source','datasetGuid'=>'10','snapshot'=>'tank/source@next','guid'=>'300'];
$checkpoint=['role'=>'checkpoint','endpoint'=>'local','dataset'=>'backup/target','datasetGuid'=>'20','snapshot'=>'backup/target@base','guid'=>'200'];
$inspection=['mode'=>'incremental','sourceDatasetGuid'=>'10','destinationDatasetGuid'=>'20','references'=>[$source,$checkpoint],
 'base'=>['snapshot'=>'tank/source@base','guid'=>'200','txg'=>'2','destinationSnapshot'=>'backup/target@base']];
$plan=zfsas_replication_plan($request['replication'],$inspection,$revision,'20M');
$publication=['taskId'=>$id,'token'=>$token,'generation'=>'generation','sequence'=>1,'type'=>'plan','payload'=>$plan];
$j->workerReport($publication,'generation',1);$j->workerReport($publication,'generation',1);
check($j->deletionReferenceOwners('backup/target@base','200')===[$receipt['runId']],'Published plan checkpoint is unprotected');
check(!in_array($id.':transfer',$j->runnable(1),true),'Transfer admitted before preparation and space');
$j->result($id,$token,['outcome'=>'success'],1,1,true);
check(in_array($id.':space',$j->runnable(1),true),'Preparation did not release space task');
check(!in_array($id.':transfer',$j->runnable(1),true),'Transfer bypassed space approval');
$j->cancel($receipt['runId'],2);
reject(fn()=>$j->workerReport($publication,'generation',2));
check(!$j->deletionReferenceOwners('backup/target@base','200'),'Canceled queued plan retained stale protection');
$bad=$inspection;$bad['mode']='full_requires_receiver_approval';reject(fn()=>zfsas_replication_plan($request['replication'],$bad,$revision));
$done=$inspection;$done['mode']='already_received';
check(count(zfsas_replication_plan($request['replication'],$done,$revision)['tasks'])===1,'Proven completion creates a transfer');
echo "PASS: native manual admission pins selection, immutable settings and receipts, atomic checkpoint publication, ordered space/transfer graph and cancellation fencing\n";
