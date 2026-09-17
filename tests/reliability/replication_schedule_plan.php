<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-schedule-plan.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe publication accepted');}
$j=new ZfsasCoordinatorState('/tmp/schedule-plan-'.bin2hex(random_bytes(8)));
$run=$j->submit('schedule',['tasks'=>['prepare'=>['kind'=>'prepare','parameters'=>['allowDynamicPlan'=>true,'nativePlan'=>true]]]],1)['runId'];
$task=$run.':prepare';$token=$j->claim($task,1,1,'generation');$j->started($task,$token,123,'123');$chunks=0;
function zfsas_coordinator_worker_report($type,$sequence,$payload){
 global $j,$task,$token,$chunks;
 if($type==='plan_chunk'){check(count($payload['tasks'])<=50,'Oversized publication chunk');$chunks++;}
 $result=$j->workerReport(['taskId'=>$task,'token'=>$token,'generation'=>'generation','sequence'=>$sequence,'type'=>$type,'payload'=>$payload],'generation',1);
 if($type==='plan_chunk'){check(count($j->state['tasks'])===1,'Unsealed plan admitted mutations');}
 return $result;
}
$rows="tank/root\t10\n";for($i=1;$i<26;$i++)$rows.="tank/root/c$i\t".(10+$i)."\n";
$parameters=['job'=>['source'=>'tank/root','destination'=>'backup/root','children'=>'1','transport'=>'local','id'=>'abcdef123456'],
 'revision'=>str_repeat('a',64),'rateLimit'=>'0','snapshotName'=>'snapsync-send-test','occurrence'=>100];
$plan=zfsas_replication_schedule_plan($parameters,fn()=>$rows);$sequence=1;
zfsas_replication_publish_plan($plan,$sequence);
check($chunks===2&&count($j->state['tasks'])===54,'Bounded schedule graph not sealed completely');
$j->result($task,$token,['outcome'=>'success'],1,1,true);
$snapshot=$task.':snapshot-00000';$t=$j->claim($snapshot,1,1,'generation');$j->started($snapshot,$t,123,'123');
$reference=['role'=>'source','endpoint'=>'local','dataset'=>'tank/root','datasetGuid'=>'10','snapshot'=>'tank/root@snapsync-send-test','guid'=>'123'];
$request=['taskId'=>$snapshot,'token'=>$t,'generation'=>'generation','sequence'=>1,'type'=>'result','payload'=>['outcome'=>'success','reference'=>$reference]];
$bad=$request;$bad['payload']['reference']['datasetGuid']='11';$before=$j->state;
reject(fn()=>$j->workerReport($bad,'generation',1));check($before===$j->state,'Bad snapshot outcome partially committed');
$j->workerReport($request,'generation',1);
check($j->deletionReferenceOwners($reference['snapshot'],'123')===[$run],'Snapshot outcome was acknowledged without protection');
check(!in_array($task.':member-00000',$j->runnable(1),true),'Snapshot result bypassed verified shutdown');
$j->result($snapshot,$t,$request['payload'],1,1,true);
check(in_array($task.':member-00000',$j->runnable(1),true),'Verified snapshot did not release member planning');
$j->cancel($run,2);
echo "PASS: bounded scheduled plan sealing, fixed membership, exact snapshot-result publication and verified shutdown dependency\n";
