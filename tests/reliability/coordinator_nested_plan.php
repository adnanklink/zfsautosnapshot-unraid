<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$root='/tmp/nested-plan-'.bin2hex(random_bytes(8));$j=new ZfsasCoordinatorState($root);
$run=$j->submit('nested',['tasks'=>[
 'prepare'=>['kind'=>'prepare','parameters'=>['allowDynamicPlan'=>true]],
 'consumer'=>['kind'=>'send','dependencies'=>['prepare']],
 'final'=>['kind'=>'finalize','dependencies'=>['consumer','prepare']]]],1)['runId'];
$parent=$run.':prepare';$token=$j->claim($parent,1,1,'generation');$j->started($parent,$token,123,'123');
$plan=['tasks'=>[
 'nested'=>['kind'=>'prepare','dataset'=>'tank/data','parameters'=>['allowDynamicPlan'=>true]],
 'finish'=>['kind'=>'finalize','dataset'=>'tank/data']]];
$report=['taskId'=>$parent,'token'=>$token,'generation'=>'generation','sequence'=>1,'type'=>'plan','payload'=>$plan];
$j->workerReport($report,'generation',1);$j->workerReport($report,'generation',1);
check(count($j->state['tasks'][$run.':consumer']['dependencies'])===2,'Duplicate plan duplicated completion edges');
$j->result($parent,$token,['outcome'=>'success'],1,1,true);
check(!in_array($run.':consumer',$j->runnable(1),true),'Consumer ran after planner exit');
$nested=$parent.':nested';$token=$j->claim($nested,1,1,'generation');$j->started($nested,$token,123,'123');
$plan=['tasks'=>['send'=>['kind'=>'send','dataset'=>'tank/data'],'verify'=>['kind'=>'finalize','dataset'=>'tank/data']]];
$j->workerReport(['taskId'=>$nested,'token'=>$token,'generation'=>'generation','sequence'=>1,'type'=>'plan','payload'=>$plan],'generation',1);
$j->result($nested,$token,['outcome'=>'success'],1,1,true);
check(in_array($nested.':verify',$j->state['tasks'][$parent.':finish']['dependencies'],true),'Outer finalizer omitted nested completion');
$j->checkpoint();unset($j);$j=new ZfsasCoordinatorState($root);
foreach ([$nested.':send',$nested.':verify',$parent.':finish'] as $id) {
 check(!in_array($run.':consumer',$j->runnable(1),true),'Restart or partial child completion released consumer');
 check(in_array($id,$j->runnable(1),true),'Completion dependency cannot progress');
 $token=$j->claim($id,1,1);$j->started($id,$token,123,'123');$j->result($id,$token,['outcome'=>'success'],1,1,true);
}
check(in_array($run.':consumer',$j->runnable(1),true),'Verified nested completion did not release consumer');
check(!in_array($run.':final',$j->runnable(1),true),'Run finalizer bypassed consumer');
$j->cancel($run,2);
check($j->state['runs'][$run]['state']==='canceled','Nested graph cancellation failed');
echo "PASS: nested preparation completion barriers, duplicate publication, restart, ordered finalization and cancellation\n";
