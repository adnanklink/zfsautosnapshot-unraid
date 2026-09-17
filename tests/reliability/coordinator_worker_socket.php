<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Use the disposable test container.'); }
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-socket.php';
$socket = '/var/run/zfs-snapsync-coordinator/control.sock';
if (($argv[1] ?? '') === 'server') {
    $root = $argv[2];
    $journal = new ZfsasCoordinatorState($root);
    $executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime',
        fn($task) => [PHP_BINARY, __FILE__, 'worker', $task['kind'], $task['parameters']['scenario'], $root],
        fn($task, $code) => ['outcome' => $code === 0 ? 'success' : 'transient_failure']);
    $server = new ZfsasCoordinatorSocket($socket, function($request) use ($journal, $executor) {
        return match ($request['action'] ?? '') {
            'status' => $journal->state,
            'submit' => $journal->submit($request['commandId'], $request['spec'], time()),
            'worker_report' => $executor->workerReport($request),
            default => throw new InvalidArgumentException('Unknown command'),
        };
    }, fn($now) => $executor->tick($now));
    $server->serve(); exit;
}
if (($argv[1] ?? '') === 'worker') {
    require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php';
    [$mode, $kind, $scenario, $root] = array_slice($argv, 1);
    $sequence = 1;
    $progress = ['phase' => 'preparing', 'message' => 'Fixture inspection', 'percent' => 0];
    zfsas_coordinator_worker_report('progress', $sequence, $progress);
    zfsas_coordinator_worker_report('progress', $sequence++, $progress); // Lost acknowledgement replay.
    if ($kind === 'prepare') {
        zfsas_coordinator_worker_report('plan', $sequence++, ['tasks' => [
            'transfer' => ['kind'=>'send', 'dataset'=>'tank/data', 'parameters'=>['scenario'=>$scenario]],
            'finalize' => ['kind'=>'finalize', 'dataset'=>'tank/data', 'parameters'=>['scenario'=>$scenario]],
        ]]);
    }
    file_put_contents($root . '/executed', $scenario . ':' . $kind . "\n", FILE_APPEND | LOCK_EX);
    zfsas_coordinator_worker_report('result', $sequence, [
        'outcome' => $kind === 'send' && $scenario === 'fail' ? 'validation_failure' : 'success',
        'message' => 'Explicit ' . $kind . ' result',
    ]);
    exit(0); // An explicit failure must override this successful process exit.
}
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$root = '/tmp/coordinator-worker-socket-' . bin2hex(random_bytes(8)); mkdir($root);
$process = proc_open([PHP_BINARY, __FILE__, 'server', $root], [0=>['file','/dev/null','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']], $pipes);
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        try { if (zfsas_coordinator_request(['action'=>'status'], $socket, .1)['ok']) { $ready = true; break; } }
        catch (RuntimeException $error) { usleep(20000); }
    }
    check($ready, 'Server failed: ' . file_get_contents($root.'/server.log'));
    foreach (['success', 'fail'] as $scenario) {
        $receipt = zfsas_coordinator_request(['action'=>'submit', 'commandId'=>$scenario, 'spec'=>['tasks'=>[
            'prepare'=>['kind'=>'prepare', 'dataset'=>'tank/data', 'parameters'=>['allowDynamicPlan'=>true, 'scenario'=>$scenario]],
        ]]], $socket);
        check($receipt['ok'], 'Submit failed');
        $run = $receipt['result']['runId'];
        $deadline = microtime(true) + 15;
        do {
            usleep(20000);
            $state = zfsas_coordinator_request(['action'=>'status'], $socket)['result'];
            if (ZfsasCoordinatorState::terminal($state['runs'][$run]['state'])) { break; }
        } while (microtime(true) < $deadline);
        check($state['runs'][$run]['state'] === ($scenario === 'success' ? 'complete' : 'failed'), 'Explicit child outcome was lost');
        check(count($state['runs'][$run]['tasks']) === 3, 'Plan publication duplicated or lost children');
        $events = file($root . '/executed', FILE_IGNORE_NEW_LINES);
        check(in_array($scenario . ':finalize', $events, true) === ($scenario === 'success'), 'Finalizer admitted without successful children');
        $task = $state['tasks'][$run . ':prepare'];
        check($task['progress']['message'] === 'Fixture inspection', 'Progress RPC lost');
        $attempt = array_values(array_filter($state['attempts'], fn($a) => $a['taskId'] === $task['id']))[0];
        $token = array_search($attempt, $state['attempts'], true);
        $stale = zfsas_coordinator_request(['action'=>'worker_report', 'taskId'=>$task['id'], 'token'=>$token,
            'generation'=>$attempt['generation'], 'sequence'=>4, 'type'=>'progress', 'payload'=>['phase'=>'stale']], $socket);
        check(!$stale['ok'], 'Stopped worker retained publication authority');
    }
    echo "PASS: granted workers publish over Unix socket, duplicate report, atomic child plan, explicit failure, finalization dependencies and stale-worker rejection\n";
} finally {
    proc_terminate($process, 9); proc_close($process);
    @unlink($socket);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($root);
}
