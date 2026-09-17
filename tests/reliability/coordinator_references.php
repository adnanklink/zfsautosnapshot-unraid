<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-executor.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejects($fn, $message) { try { $fn(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException($message); }
$root = '/tmp/coordinator-references-' . bin2hex(random_bytes(6));
$j = new ZfsasCoordinatorState($root);
$reference = ['role'=>'base','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'42','snapshot'=>'tank/data@base','guid'=>'123'];
$spec = ['tasks'=>['transfer'=>['kind'=>'send','references'=>[$reference]]]];
$bad = $spec; unset($bad['tasks']['transfer']['references'][0]['datasetGuid']);
rejects(fn() => $j->submit('bad', $bad, 1), 'Incomplete reference accepted');
check(!$j->state['runs'] && !$j->state['references'], 'Rejected submission partially published');
$owner = $j->submit('owner', $spec, 1)['runId'];
$other = $j->submit('other-owner', $spec, 1)['runId'];
check(count($j->deletionReferenceOwners('tank/data@base','123')) === 2, 'Shared reference lost owner');
$j->cancel($owner, 2);
check($j->deletionReferenceOwners('tank/data@base','123') === [$other], 'Cancellation released another run reference');
check(!$j->deletionReferenceOwners('tank/data@unrelated','999'), 'Unrelated snapshot was blocked');
unset($j); $j = new ZfsasCoordinatorState($root);
check($j->deletionReferenceOwners('tank/data@base','123') === [$other], 'Restart lost reference protection');
// A completed transfer keeps the reference until the entire run finalizes.
$run = $j->submit('finalizing', ['tasks'=>[
    'send'=>['kind'=>'send','references'=>[$reference]],
    'final'=>['kind'=>'finalize','dependencies'=>['send']]]], 1)['runId'];
$t = $j->claim($run . ':send', 1, 1); $j->started($run . ':send', $t, 123, '123');
$j->result($run . ':send', $t, ['outcome'=>'success'], 1, 1, true);
check(in_array($run,$j->deletionReferenceOwners('tank/data@base','123'),true), 'Transfer completion prematurely released checkpoint');
$t = $j->claim($run . ':final', 1, 1); $j->started($run . ':final', $t, 123, '123');
$j->result($run . ':final', $t, ['outcome'=>'validation_failure','recoveryRequired'=>true], 1, 1, true);
check(in_array($run,$j->deletionReferenceOwners('tank/data@base','123'),true), 'Ambiguous recovery lost reference');
$j->prune(32*86400);
check(isset($j->state['runs'][$run]), 'Pruning removed recovery evidence');
check(!isset($j->state['runs'][$owner]), 'Pruning retained canceled owner');
check(!array_filter($j->state['references'], fn($r) => $r['runId'] === $owner), 'Pruning retained orphan references');
// A live deletion must stop before a planner may claim its snapshot.
$deletion = $j->submit('active-delete', ['tasks'=>['delete'=>['kind'=>'delete', 'parameters'=>[
    'deleteJob'=>['SNAPSHOT'=>'tank/data@other','SNAPSHOT_GUID'=>'456']]]]], 1)['runId'];
$t = $j->claim($deletion . ':delete', 1, 1);
$conflict = $reference; $conflict['snapshot']='tank/data@other'; $conflict['guid']='456';
rejects(fn() => $j->submit('racing-plan', ['tasks'=>['send'=>['kind'=>'send','references'=>[$conflict]]]],1), 'Planner claimed active deletion');
// Preparation commits references and children atomically; invalid cleanup does not leak either.
$prep = $j->submit('prepare', ['tasks'=>['plan'=>['kind'=>'prepare','parameters'=>['allowDynamicPlan'=>true]]]],1)['runId'].':plan';
$token=$j->claim($prep,1,1,'generation'); $j->started($prep,$token,123,'123');
$plan=['tasks'=>['transfer'=>['kind'=>'send','dataset'=>'tank/data','references'=>[$reference]],
    'cleanup'=>['kind'=>'delete','dataset'=>'tank/data','parameters'=>['deleteJob'=>['SNAPSHOT'=>'tank/data@base','SNAPSHOT_GUID'=>'123']]],
    'final'=>['kind'=>'finalize','dataset'=>'tank/data']]];
$report=['taskId'=>$prep,'token'=>$token,'generation'=>'generation','sequence'=>1,'type'=>'plan','payload'=>$plan];
$before=$j->state;
rejects(fn()=>$j->workerReport($report,'generation',1),'Self-destructive cleanup plan accepted');
check($j->state===$before,'Rejected plan partially changed journal');
$report['payload']['tasks']['cleanup']['parameters']['deleteJob']=['SNAPSHOT'=>'tank/data@unrelated','SNAPSHOT_GUID'=>'999'];
$j->workerReport($report,'generation',1);
check(count($j->state['references']) > count($before['references']), 'Plan references not registered');
check(!in_array($prep.':cleanup',$j->runnable(1),true),'Cleanup ran before preparation finished');
// Dependency admission waits use no process/attempt or retry allowance.
$wait = $j->submit('waiting-delete', ['tasks'=>['delete'=>['kind'=>'delete']]],1)['runId'].':delete';
$j->deferAdmission($wait,['outcome'=>'wait','reason'=>'dependency'],10,100);
check($j->state['tasks'][$wait]['attemptCount']===0 && $j->state['tasks'][$wait]['attempt']===null, 'Wait spent execution authority');
check(!in_array($wait,$j->runnable(39),true) && in_array($wait,$j->runnable(40),true),'Admission deadline ignored');
// Large inventories must not turn each unrelated cleanup query into a scan.
$tasks = [];
for ($chunk=0; $chunk<10; $chunk++) {
    $refs=[];
    for ($i=0; $i<1000; $i++) {
        $n=$chunk*1000+$i;
        $refs[]=array_replace($reference,['snapshot'=>'tank/data@scale'.$n,'guid'=>(string)(10000+$n)]);
    }
    $tasks['send'.$chunk]=['kind'=>'send','references'=>$refs];
}
$large = $j->submit('large', ['tasks'=>$tasks], 1)['runId'];
$start=hrtime(true);
for($i=0;$i<10000;$i++) { check(!$j->deletionReferenceOwners('tank/data@absent','999999'), 'Unrelated scale query matched'); }
check((hrtime(true)-$start)/1e9<2, 'Reference lookups repeatedly scanned inventory');
check($j->deletionReferenceOwners('tank/data@scale9999','19999')===[$large], 'Indexed tail reference missing');
unset($j); $j=new ZfsasCoordinatorState($root);
check($j->deletionReferenceOwners('tank/data@scale9999','19999')===[$large], 'Restart did not rebuild reference index');
echo "PASS: exact reference identity, atomic planning, shared protection, active-delete race, recovery retention and worker-free waits\n";
