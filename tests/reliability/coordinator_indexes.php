<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$root = '/tmp/coordinator-indexes-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root);
    $tasks = ['prepare'=>['kind'=>'prepare']];
    for ($i = 0; $i < 10000; $i++) { $tasks['send-' . $i] = ['kind'=>'send', 'dependencies'=>['prepare']]; }
    $run = $journal->submit('large', ['tasks'=>$tasks], 1)['runId'];
    $prepare = $run . ':prepare';
    $token = $journal->claim($prepare, 1, 1);
    check($journal->activeTaskIds() === [$prepare], 'Active index omitted launch');
    $start = microtime(true);
    for ($i = 0; $i < 2000; $i++) { check($journal->runnable(1) === [], 'Blocked dependency admitted'); }
    $idle = microtime(true) - $start;
    $journal->result($prepare, $token, ['outcome'=>'success'], 2, 2, true);
    check(count($journal->runnable(2)) === 10000, 'Reverse dependency index lost children');
    check($journal->activeTaskIds() === [], 'Stopped attempt stayed active');
    $first = $run . ':send-0'; $token = $journal->claim($first, 2, 2);
    $journal->result($first, $token, ['outcome'=>'wait','reason'=>'resource','delay'=>10], 2, 2, true);
    check(!in_array($first, $journal->runnable(11), true), 'Deadline admitted early');
    check($journal->nextDeadline(2) === 12.0, 'Earliest wait deadline lost');
    check(in_array($first, $journal->runnable(12), true), 'Deadline did not become ready');
    $journal->cancel($run, 3);
    check($journal->runnable(100) === [], 'Cancellation left ready tasks or stale deadlines');
    unset($journal);
    $journal = new ZfsasCoordinatorState($root);
    check($journal->runnable(100) === [] && $journal->activeTaskIds() === [], 'Restart rebuilt stale indexes');
    echo 'PASS: 10,000-task dependency admission, active ownership, monotonic deadlines, cancellation and index rebuild; 2,000 idle reads in ' . round($idle, 4) . "s\n";
} finally {
    unset($journal);
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); } if (is_dir($root)) { rmdir($root); }
}
