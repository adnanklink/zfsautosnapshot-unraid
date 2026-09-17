<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
$root = '/tmp/zfsas-coordinator-test-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root);
    $spec = ['schedule' => 'schedule-1', 'occurrence' => 100, 'revision' => 'config-a', 'tasks' => [
        'prepare' => ['kind' => 'prepare'], 'transfer' => ['kind' => 'send', 'dependencies' => ['prepare']],
        'finalize' => ['kind' => 'finalize', 'dependencies' => ['transfer']]]];
    $receipt = $journal->submit('command-1', $spec, 100);
    check($receipt === $journal->submit('command-1', $spec, 101), 'Duplicate command created another run');
    check($receipt === $journal->submit('command-1', array_reverse($spec, true), 101), 'JSON key order changed idempotency');
    check(count($journal->state['runs']) === 1, 'Duplicate run');
    $run = $receipt['runId']; $prepare = "$run:prepare"; $transfer = "$run:transfer";
    $token = $journal->claim($prepare, 10, 100);
    check(!$journal->result($prepare, 'stale', ['outcome' => 'success'], 10, 100, true), 'Stale attempt accepted');
    check(!$journal->result($prepare, $token, ['outcome' => 'success'], 10, 100, false), 'Unverified shutdown accepted');
    check($journal->result($prepare, $token, ['outcome' => 'wait', 'reason' => 'array'], 10, 100, true), 'Wait rejected');
    check($journal->state['tasks'][$prepare]['attemptCount'] === 0, 'Wait consumed attempt');
    check($journal->runnable(39) === [], 'Wait deadline ignored');
    check($journal->runnable(40) === [$prepare], 'Wait not eligible');
    $token = $journal->claim($prepare, 40, 99999);
    $journal->result($prepare, $token, ['outcome' => 'success'], 40, 99999, true);
    check($journal->runnable(40) === [$transfer], 'Dependency did not unblock');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $mono = [1 => 40, 2 => 100, 3 => 400][$attempt];
        $token = $journal->claim($transfer, $mono, 200);
        $journal->result($transfer, $token, ['outcome' => 'transient_failure'], $mono, 200, true);
        if ($attempt < 3) { check($journal->runnable($mono + ($attempt === 1 ? 59 : 299)) === [], 'Retry deadline moved with wall time'); }
    }
    check($journal->state['runs'][$run]['state'] === 'failed', 'Retry exhaustion did not fail run');
    check($journal->state['tasks']["$run:finalize"]['state'] !== 'complete', 'Missing child success finalized');
    $same = $journal->submit('same-occurrence', $spec, 300);
    check(($same['blocked'] ?? '') === 'occurrence_accepted', 'Exhausted occurrence recreated');
    $spec['occurrence'] = 200;
    $next = $journal->submit('next-occurrence', $spec, 300);
    check($next['runId'] !== $run, 'Exhaustion blocked next scheduled occurrence');
    $nextTask = $next['runId'] . ':prepare';
    $nextToken = $journal->claim($nextTask, 500, 300);
    $journal->started($nextTask, $nextToken, 1234, '500');
    $tokens = $journal->cancel($next['runId'], 301);
    check($tokens === [$nextToken], 'Cancellation omitted active attempt');
    check(!$journal->result($nextTask, $nextToken, ['outcome' => 'success'], 501, 301, true), 'Canceled result accepted');
    check($journal->state['runs'][$next['runId']]['state'] === 'canceling', 'Shutdown acknowledged before confirmation');
    $journal->stopped($nextToken, 302, 502);
    check($journal->state['runs'][$next['runId']]['state'] === 'canceled', 'Verified cancellation not finalized');
    $sequence = $journal->state['sequence'];
    unset($journal);
    file_put_contents($root . '/checkpoint.pending', '{partial');
    $journal = new ZfsasCoordinatorState($root);
    check($journal->state['sequence'] === $sequence, 'Interrupted publication replaced accepted state');
    check($journal->submit('command-1', array_replace($spec, ['occurrence' => 100]), 400) === $receipt, 'Restart lost idempotency');
    $journal->prune(4000000);
    check(!$journal->state['runs'], 'Terminal retention did not expire');
    check($journal->submit('command-1', array_replace($spec, ['occurrence' => 100]), 4000001) === $receipt, 'Pruning lost command identity');
    unset($journal);
    file_put_contents($root . '/checkpoint.json', '{"payload":"{}","sha256":"bad"}');
    try { new ZfsasCoordinatorState($root); throw new RuntimeException('Corrupt journal accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'corrupt'), 'Wrong corruption failure'); }
    echo "PASS: coordinator idempotency, dependencies, three attempts, monotonic waits, occurrence acceptance, cancellation, restart, interrupted publication, corruption and retention\n";
} finally {
    unset($journal);
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
}
