<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-state.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function rejected(callable $fn) { try { $fn(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Invalid publication accepted'); }
$root = '/tmp/zfsas-worker-protocol-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root);
    $receipt = $journal->submit('prepare', ['tasks' => ['prepare' => ['kind'=>'prepare', 'parameters'=>['allowDynamicPlan'=>true]]]], 100);
    $task = $receipt['runId'] . ':prepare'; $generation = str_repeat('a', 48);
    $token = $journal->claim($task, 0, 100, $generation);
    $base = ['taskId'=>$task, 'token'=>$token, 'generation'=>$generation];
    $progress = $base + ['sequence'=>1,'type'=>'progress','payload'=>['phase'=>'planning','percent'=>20]];
    rejected(fn() => $journal->workerReport($progress, $generation, 101));
    $journal->started($task,$token,123,'1234');
    $journal->workerReport($progress, $generation, 101);
    $sequence = $journal->state['sequence'];
    $journal->workerReport($progress, $generation, 102);
    check($journal->state['sequence'] === $sequence, 'Duplicate report rewrote journal');
    rejected(fn() => $journal->workerReport(array_replace($progress,['generation'=>'old']), $generation, 102));
    rejected(fn() => $journal->workerReport(array_replace($progress,['token'=>'stale']), $generation, 102));
    rejected(fn() => $journal->workerReport(array_replace($progress,['payload'=>['percent'=>40]]), $generation, 102));
    rejected(fn() => $journal->workerReport(array_replace($progress,['sequence'=>3]), $generation, 102));
    $plan = ['tasks'=>['one'=>['kind'=>'send','dataset'=>'tank/a','parameters'=>['guid'=>'1']],
        'two'=>['kind'=>'send','dataset'=>'tank/b','parameters'=>['guid'=>'2']],
        'finish'=>['kind'=>'finalize','dataset'=>'tank/a']]];
    $invalid = $plan; $invalid['tasks']['one']['dependencies'] = ['finish'];
    rejected(fn() => $journal->workerReport($base+['sequence'=>2,'type'=>'plan','payload'=>$invalid], $generation, 102));
    check(count($journal->state['tasks']) === 1, 'Invalid graph partially published');
    $journal->workerReport($base+['sequence'=>2,'type'=>'plan','payload'=>$plan], $generation, 102);
    check(count($journal->state['tasks']) === 4, 'Fan-out not committed');
    check($journal->runnable(10) === [], 'Children started before preparation shutdown');
    $finish = $task . ':finish';
    check(count($journal->state['tasks'][$finish]['dependencies']) === 3, 'Finalizer omitted expected child');
    $changed = $plan; $changed['tasks']['one']['parameters']['guid'] = 'changed';
    rejected(fn() => $journal->workerReport($base+['sequence'=>3,'type'=>'plan','payload'=>$changed], $generation, 102));
    $success = $base+['sequence'=>3,'type'=>'result','payload'=>['outcome'=>'success']];
    $journal->workerReport($success, $generation, 103);
    check($journal->state['tasks'][$task]['state'] === 'running', 'Report falsely completed active worker');
    check(!$journal->result($task,$token,['outcome'=>'success'],10,103,false),'Unverified worker completion');
    $journal->result($task,$token,$journal->state['attempts'][$token]['reportedResult'],10,103,true);
    check(count($journal->runnable(10)) === 2, 'Verified preparation did not admit children');
    rejected(fn() => $journal->workerReport($success, $generation, 104));
    $child = $task . ':one'; $childToken = $journal->claim($child,10,104,$generation);
    $journal->started($child,$childToken,124,'1235');
    $journal->cancel($receipt['runId'],105);
    rejected(fn() => $journal->workerReport(['taskId'=>$child,'token'=>$childToken,'generation'=>$generation,'sequence'=>1,'type'=>'result','payload'=>['outcome'=>'success']],$generation,105));
    $journal->stopped($childToken,106,11);
    check($journal->state['runs'][$receipt['runId']]['state']==='canceled','Fan-out cancellation failed');
    $journal->checkpoint();
    unset($journal);
    $record=json_decode(file_get_contents($root.'/checkpoint.json'),true);
    $legacy=json_decode($record['payload'],true);$legacy['version']=1;
    $payload=json_encode($legacy);file_put_contents($root.'/checkpoint.json',json_encode(['payload'=>$payload,'sha256'=>hash('sha256',$payload)]));
    $journal=new ZfsasCoordinatorState($root);
    check($journal->state['version']===3 && count($journal->state['tasks'])===4,'Legacy checkpoint migration lost records');
    echo "PASS: generation/attempt/sequence fences, idempotent publication, atomic fan-out, finalizer membership, verified completion, cancellation and v1 journal loading\n";
} finally {
    unset($journal);
    foreach (glob($root.'/*') ?: [] as $file) { unlink($file); }
    if(is_dir($root))rmdir($root);
}
