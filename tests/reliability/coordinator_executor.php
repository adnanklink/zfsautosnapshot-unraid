<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$root = '/tmp/zfsas-executor-test-' . bin2hex(random_bytes(8));
mkdir($root);
$fixture = $root . '/worker.sh';
file_put_contents($fixture, "#!/bin/bash\nif [[ \"\$1\" == orphan ]]; then sleep 60 & echo \$! > \"\$2\"; exit 0; fi\nsleep \"\$1\"\n");
chmod($fixture, 0755);
$journal = new ZfsasCoordinatorState($root);
$command = fn($task) => ['/bin/bash', $fixture, $task['parameters']['sleep'], $root . '/child'];
$outcome = fn($task, $code) => ['outcome' => $code === 0 ? 'success' : 'transient_failure'];
$transitions = [];
$executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime', $command, $outcome, [],
    static function ($id) use (&$transitions, $journal) { $transitions[$id] = $journal->state['tasks'][$id]['state']; });
function drive($executor, $predicate, $timeout = 8): void {
    $deadline = microtime(true) + $timeout;
    do { $executor->tick(hrtime(true) / 1e9); if ($predicate()) { return; } usleep(20000); } while (microtime(true) < $deadline);
    throw new RuntimeException('Executor fixture timed out');
}
try {
    $pending = $journal->submit('pending-cancel', ['tasks' => ['one' => ['kind' => 'delete']]], time());
    $executor->cancel($pending['runId']);
    check(($transitions[$pending['runId'] . ':one'] ?? '') === 'canceled', 'Queued cancellation did not publish its terminal transition');
    $receipt = $journal->submit('one', ['manual' => true, 'tasks' => ['one' => ['kind' => 'auto', 'parameters' => ['sleep' => '.05']]]], time());
    drive($executor, fn() => $journal->state['runs'][$receipt['runId']]['state'] === 'complete');
    $receipt = $journal->submit('reported', ['tasks' => ['one' => ['kind'=>'auto', 'parameters'=>['sleep'=>'.2']]]], time());
    $task = $receipt['runId'] . ':one';
    drive($executor, fn() => $journal->state['tasks'][$task]['state'] === 'running');
    $token = $journal->state['tasks'][$task]['attempt'];
    $generation = $journal->state['attempts'][$token]['generation'];
    $executor->workerReport(['taskId'=>$task,'token'=>$token,'generation'=>$generation,'type'=>'result','sequence'=>1,
        'payload'=>['outcome'=>'validation_failure','message'=>'Reported validation failure']]);
    check($journal->state['runs'][$receipt['runId']]['state']==='running','Report released running worker');
    drive($executor, fn() => $journal->state['runs'][$receipt['runId']]['state'] === 'failed');
    check($journal->state['tasks'][$task]['result']['message']==='Reported validation failure','Explicit worker result was ignored');
    $receipt = $journal->submit('cancel', ['manual' => true, 'tasks' => ['one' => ['kind' => 'auto', 'parameters' => ['sleep' => '60']]]], time());
    $task = $receipt['runId'] . ':one';
    drive($executor, fn() => $journal->state['tasks'][$task]['state'] === 'running');
    $attempt = $journal->state['attempts'][$journal->state['tasks'][$task]['attempt']];
    $executor->cancel($receipt['runId']);
    check($journal->state['runs'][$receipt['runId']]['state'] === 'canceling', 'Cancellation released an active worker');
    drive($executor, fn() => $journal->state['runs'][$receipt['runId']]['state'] === 'canceled');
    check(ZfsasCoordinatorExecutor::members($attempt['pid'], $attempt['start']) === [], 'Cancellation left children');
    $receipt = $journal->submit('orphan', ['manual' => true, 'tasks' => ['one' => ['kind' => 'auto', 'parameters' => ['sleep' => 'orphan']]]], time());
    $task = $receipt['runId'] . ':one';
    drive($executor, fn() => $journal->state['tasks'][$task]['state'] === 'stopping');
    $orphan = (int) file_get_contents($root . '/child');
    check(ZfsasCoordinatorExecutor::identity($orphan) !== null, 'Fixture did not create pipeline child');
    $executor->cancel($receipt['runId']);
    drive($executor, fn() => $journal->state['runs'][$receipt['runId']]['state'] === 'canceled');
    $identity = ZfsasCoordinatorExecutor::identity($orphan);
    check($identity === null || $identity['state'] === 'Z', 'Orphan survived verified cancellation');
    echo "PASS: launch permission follows identity publication, execution completion, cancel versus shutdown, orphan pipeline termination\n";
} finally {
    foreach ($journal->state['runs'] as $run) { if (!ZfsasCoordinatorState::terminal($run['state'])) { $executor->cancel($run['id']); } }
    for ($round = 0; $round < 50; $round++) { $executor->tick(hrtime(true) / 1e9); usleep(20000); }
    unset($executor, $journal);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}
