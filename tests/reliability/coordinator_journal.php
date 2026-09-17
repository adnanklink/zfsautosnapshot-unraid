<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejected($root) {
    try { $journal = new ZfsasCoordinatorState($root); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Corrupt log accepted');
}
$root = '/tmp/coordinator-journal-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root);
    $receipt = $journal->submit('first', ['tasks'=>['one'=>['kind'=>'auto']]], 1);
    $checkpoint = file_get_contents($root . '/checkpoint.json');
    $task = $receipt['runId'] . ':one';
    $token = $journal->claim($task, 1, 1);
    $journal->started($task, $token, 123, '123');
    check(file_get_contents($root . '/checkpoint.json') === $checkpoint, 'Every transition still rewrites checkpoint');
    $expected = $journal->state; unset($journal);
    $validLog = file_get_contents($root . '/journal.ndjson');
    file_put_contents($root . '/journal.ndjson', '{unfinished', FILE_APPEND);
    $journal = new ZfsasCoordinatorState($root);
    check($journal->state === $expected, 'Torn append lost committed ownership');
    check(file_get_contents($root . '/journal.ndjson') === $validLog, 'Torn tail was not repaired before next append');
    $journal->result($task, $token, ['outcome'=>'success'], 2, 2, true);
    $expected = $journal->state;
    $coveredLog = file_get_contents($root . '/journal.ndjson');
    $journal->checkpoint(); unset($journal);
    // Crash after publishing checkpoint but before removing the covered log.
    file_put_contents($root . '/journal.ndjson', $coveredLog);
    $journal = new ZfsasCoordinatorState($root);
    check($journal->state === $expected, 'Compaction replay repeated covered events');
    $journal->submit('second', ['tasks'=>['one'=>['kind'=>'auto']]], 3);
    $expected = $journal->state; unset($journal);
    check(ZfsasCoordinatorState::readCommitted($root) === $expected, 'Read-only replay omitted latest acceptance');
    $validLog = file_get_contents($root . '/journal.ndjson');
    $lines = explode("\n", trim($validLog));
    $last = json_decode(array_pop($lines), true);
    $event = json_decode($last['payload'], true); $event['sequence'] += 2;
    $payload = json_encode($event); $bad = json_encode(['payload'=>$payload,'sha256'=>hash('sha256',$payload)]);
    file_put_contents($root . '/journal.ndjson', implode("\n", $lines) . "\n" . $bad . "\n");
    rejected($root);
    file_put_contents($root . '/journal.ndjson', $validLog . "{bad complete record}\n"); rejected($root);
    file_put_contents($root . '/journal.ndjson', $validLog);
    $journal = new ZfsasCoordinatorState($root);
    // Force the record-count compaction boundary with bounded progress-sized updates.
    for ($i = 0; $i < 1024; $i++) { $journal->state['schedules']['test'] = ['accepted'=>$i]; $journal->commit(); }
    $record = json_decode(file_get_contents($root . '/checkpoint.json'), true);
    $snapshot = json_decode($record['payload'], true);
    check($snapshot['sequence'] > $expected['sequence'] + 1000, 'Record limit did not compact');
    $expected = $journal->state; unset($journal);
    $journal = new ZfsasCoordinatorState($root);
    check($journal->state === $expected, 'Compaction lost final records');
    // Every pending legacy manual operation needs new approval, while completed
    // items and active process ownership survive the schema handoff.
    $manual = $journal->submit('legacy-manual', ['manual'=>true, 'tasks'=>[
        'done'=>['kind'=>'auto'], 'active'=>['kind'=>'auto'], 'queued'=>['kind'=>'auto']]], 4);
    $run = $manual['runId'];
    $done = $journal->claim($run . ':done', 4, 4);
    $journal->result($run . ':done', $done, ['outcome'=>'success'], 4, 4, true);
    $active = $journal->claim($run . ':active', 4, 4);
    $legacy = $journal->state; $legacy['version'] = 2;
    unset($journal);
    $payload = json_encode($legacy);
    file_put_contents($root . '/checkpoint.json', json_encode(['payload'=>$payload, 'sha256'=>hash('sha256', $payload)]));
    file_put_contents($root . '/journal.ndjson', '');
    $journal = new ZfsasCoordinatorState($root);
    $journal->requireUpgradeReview(5);
    check($journal->state['tasks'][$run . ':done']['state'] === 'complete', 'Upgrade lost successful item');
    check($journal->state['tasks'][$run . ':active']['attempt'] === $active, 'Upgrade discarded active ownership');
    check(!in_array($run . ':queued', $journal->runnable(5), true), 'Upgrade restored old manual approval');
    $journal->stopped($active, 5, 5);
    check($journal->state['runs'][$run]['state'] === 'failed', 'Stopped legacy run did not require review');
    check($journal->state['tasks'][$run . ':active']['result']['recoveryRequired'], 'Upgrade omitted recovery explanation');
    echo "PASS: append-only transitions, torn tail repair, corrupt/gapped record rejection, covered-record replay, compaction and restart\n";
} finally {
    unset($journal);
    foreach (glob($root . '/*') ?: [] as $file) { unlink($file); } if (is_dir($root)) { rmdir($root); }
}
