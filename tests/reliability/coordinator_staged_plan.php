<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejected($callback) { try { $callback(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Invalid plan publication accepted'); }
function canonical(array $value): array { if (!array_is_list($value)) { ksort($value, SORT_STRING); } foreach ($value as &$item) { if (is_array($item)) { $item = canonical($item); } } return $value; }
$root = '/tmp/coordinator-staged-plan-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root);
    $run = $journal->submit('large-plan', ['tasks'=>['prepare'=>['kind'=>'prepare','parameters'=>['allowDynamicPlan'=>true]]]], 1)['runId'];
    $task = $run . ':prepare'; $token = $journal->claim($task, 1, 1, 'generation');
    $journal->started($task, $token, 123, '123');
    $base = ['taskId'=>$task,'token'=>$token,'generation'=>'generation'];
    $tasks = [];
    for ($i = 0; $i < 1100; $i++) { $tasks['send-' . $i] = ['kind'=>'send','dataset'=>'tank/data-' . $i]; }
    $tasks['send-0']['references'] = [['role'=>'base','endpoint'=>'local','dataset'=>'tank/data-0',
        'datasetGuid'=>'42','snapshot'=>'tank/data-0@base','guid'=>'123']];
    $tasks['finish'] = ['kind'=>'finalize','dataset'=>'tank/data'];
    $digest = hash('sha256', json_encode(canonical(['tasks'=>$tasks]), JSON_THROW_ON_ERROR));
    $sequence = 1; $offset = 0;
    foreach (array_chunk($tasks, 50, true) as $chunk) {
        $payload = ['offset'=>$offset,'digest'=>$digest,'tasks'=>$chunk];
        $report = $base + ['sequence'=>$sequence++,'type'=>'plan_chunk','payload'=>$payload];
        $journal->workerReport($report, 'generation', 1);
        if ($offset === 0) {
            $before = $journal->state['sequence'];
            $journal->workerReport($report, 'generation', 1);
            check($before === $journal->state['sequence'], 'Lost acknowledgement duplicated a chunk');
            $bad = $base + ['sequence'=>$sequence,'type'=>'plan_chunk','payload'=>array_replace($payload,['offset'=>51])];
            rejected(fn() => $journal->workerReport($bad, 'generation', 1));
        }
        $offset += count($chunk);
        check(count($journal->state['tasks']) === 1 && !$journal->state['references'] && $journal->runnable(1) === [], 'Unsealed tasks became executable');
    }
    rejected(fn() => $journal->workerReport($base + ['sequence'=>$sequence,'type'=>'result','payload'=>['outcome'=>'success']], 'generation', 1));
    rejected(fn() => $journal->workerReport($base + ['sequence'=>$sequence,'type'=>'plan_seal','payload'=>['digest'=>$digest,'count'=>1100]], 'generation', 1));
    // Simulate coordinator restart at the storage boundary; the executor owns
    // process shutdown and generation replacement (covered by recovery fixtures).
    unset($journal); $journal = new ZfsasCoordinatorState($root);
    $journal->workerReport($base + ['sequence'=>$sequence++,'type'=>'plan_seal','payload'=>['digest'=>$digest,'count'=>1101]], 'generation', 2);
    check($journal->deletionReferenceOwners('tank/data-0@base','123') === [$run], 'Sealing did not atomically register reference ownership');
    check(count($journal->state['tasks']) === 1102, 'Seal lost large plan members');
    check(count($journal->state['tasks'][$task . ':finish']['dependencies']) === 1101, 'Finalizer omitted expected children');
    check($journal->runnable(2) === [], 'Seal released preparation ownership early');
    $journal->workerReport($base + ['sequence'=>$sequence,'type'=>'result','payload'=>['outcome'=>'success']], 'generation', 2);
    $journal->result($task, $token, ['outcome'=>'success'], 2, 2, true);
    check(count($journal->runnable(2)) === 1100, 'Verified preparation did not release transfers');
    echo "PASS: bounded large-plan chunks, replay, ordering, unsealed admission exclusion, restart, atomic sealing and complete finalizer dependencies\n";
} finally {
    unset($journal); foreach (glob($root . '/*') ?: [] as $file) { unlink($file); } if (is_dir($root)) { rmdir($root); }
}
