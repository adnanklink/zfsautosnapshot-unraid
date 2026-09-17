<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-auto-admission.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
$root = '/tmp/zfsas-replan-' . bin2hex(random_bytes(8));
$journal = new ZfsasCoordinatorState($root);
$schedule = ['version' => 1, 'kind' => 'interval', 'seconds' => 420, 'anchor' => 100];
$config = ['revision' => 'new', 'rawAuto' => "DATASETS=\"tank/new:1G\"\n", 'rawSend' => '',
    'prefixHistory' => 'send-', 'auto' => ['DATASETS' => 'tank/new:1G'], 'schedule' => $schedule];
$spec = ['schedule' => 'auto', 'occurrence' => 520, 'revision' => 'old', 'manual' => false,
    'tasks' => ['snapshot' => ['kind' => 'auto', 'parameters' => ['revision' => 'old',
        'autoConfig' => 'old', 'sendConfig' => '', 'prefixHistory' => '', 'scheduleSpec' => $schedule]]]];
try {
    $receipt = $journal->submit('scheduled', $spec, 520);
    $run = $receipt['runId']; $id = "$run:snapshot";
    $command = zfsas_coordinator_auto_command($journal, $journal->state['tasks'][$id], $config, $root);
    check($command[0] === '/usr/bin/env', 'Untouched automatic work was rejected');
    check($journal->state['runs'][$run]['revision'] === 'new', 'Run revision was not updated');
    check($journal->state['runs'][$run]['replannedFrom'] === 'old', 'Replan lost original revision');
    check($journal->state['schedules']['auto']['accepted'] === 520, 'Replan moved occurrence');
    check($journal->submit('scheduled', $spec, 530) === $receipt, 'Replan changed command receipt');
    check(file_get_contents($root . '/config/new/zfs_snapsync.conf') === $config['rawAuto'], 'Replan captured old configuration');
    file_put_contents($root . '/config/new/zfs_snapsync.conf', 'partial');
    $sequence = $journal->state['sequence'];
    zfsas_coordinator_auto_command($journal, $journal->state['tasks'][$id], $config, $root);
    check(file_get_contents($root . '/config/new/zfs_snapsync.conf') === $config['rawAuto'], 'Partial capture was reused');
    check($journal->state['sequence'] === $sequence, 'Unchanged admission rewrote journal');
    unset($journal);
    $journal = new ZfsasCoordinatorState($root);
    check($journal->state['runs'][$run]['revision'] === 'new', 'Restart lost replan');
    $token = $journal->claim($id, 1, 530);
    $journal->stopped($token, 531, 2);
    check(!$journal->replanAuto($id, 'third', [], 532), 'Recovered attempt adopted new parameters');
    $journal->cancel($run, 532);

    $manual = $spec; $manual['manual'] = true; $manual['schedule'] = '';
    $receipt = $journal->submit('manual', $manual, 533); $id = $receipt['runId'] . ':snapshot';
    $rejected = zfsas_coordinator_auto_command($journal, $journal->state['tasks'][$id], $config, $root);
    check($rejected['outcome'] === 'validation_failure', 'Manual approval silently changed');
    $attempts = count($journal->state['attempts']);
    $executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime',
        fn($task) => zfsas_coordinator_auto_command($journal, $task, $config, $root),
        fn() => throw new RuntimeException('Rejected work launched a worker'));
    $executor->tick(3);
    check($journal->state['runs'][$receipt['runId']]['state'] === 'failed', 'Validation did not settle run');
    check(count($journal->state['attempts']) === $attempts, 'Admission failure consumed an execution attempt');
    check($journal->state['tasks'][$id]['blocked'] === 'configuration', 'Failure omitted configuration reason');
    unset($executor);
    $siblings = $journal->submit('admission-siblings', ['manual' => true, 'tasks' => [
        'first' => ['kind' => 'prepare'], 'second' => ['kind' => 'prepare']]], 534);
    $executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime',
        fn() => ['outcome' => 'validation_failure', 'reason' => 'configuration'],
        fn() => throw new RuntimeException('Canceled sibling launched a worker'));
    $executor->tick(4);
    check($journal->state['runs'][$siblings['runId']]['state'] === 'failed', 'Admission failure did not settle siblings');
    check($journal->state['tasks'][$siblings['runId'] . ':second']['state'] === 'canceled', 'Admission launched a canceled sibling');
    check(count($journal->state['attempts']) === $attempts, 'Rejected sibling created an attempt');
    unset($executor);

    foreach (['converted', 'disabled', 'empty', 'legacy'] as $case) {
        $spec['occurrence'] += 420;
        $receipt = $journal->submit($case, $spec, 534); $id = $receipt['runId'] . ':snapshot';
        $changed = $config;
        if ($case === 'converted') { $changed['schedule']['anchor'] = 900; }
        if ($case === 'disabled') { $changed['schedule'] = ['version' => 1, 'kind' => 'disabled']; }
        if ($case === 'empty') { $changed['auto']['DATASETS'] = ''; }
        if ($case === 'legacy') { unset($journal->state['tasks'][$id]['parameters']['scheduleSpec']); }
        $result = zfsas_coordinator_auto_command($journal, $journal->state['tasks'][$id], $changed, $root);
        check(($result['outcome'] ?? '') === 'validation_failure', "$case configuration unexpectedly launched");
        $journal->rejectAdmission($id, $result, 4, 534);
    }
    echo "PASS: automatic pre-execution replan, stable occurrence/receipt, restart, partial captures, manual approval, recovered attempts and changed schedule rejection\n";
} finally {
    unset($executor, $journal);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}
