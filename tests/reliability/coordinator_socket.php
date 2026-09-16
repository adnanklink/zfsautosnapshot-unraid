<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-socket.php';
if (($argv[1] ?? '') === '--server') {
    $root = $argv[2];
    $journal = new ZfsasCoordinatorState($root);
    $server = new ZfsasCoordinatorSocket($root . '/control.sock', function ($request) use ($journal) {
        if (($request['action'] ?? '') === 'submit') { return $journal->submit($request['commandId'], $request['spec'], time()); }
        if (($request['action'] ?? '') === 'status') { return ['sequence' => $journal->state['sequence'], 'runs' => count($journal->state['runs'])]; }
        throw new InvalidArgumentException('Unknown command.');
    }, function ($now) use ($root) { file_put_contents($root . '/ticks', "tick\n", FILE_APPEND); return $now + 30; });
    $server->serve(); exit(0);
}
$root = '/tmp/zfsas-socket-test-' . bin2hex(random_bytes(8));
mkdir($root);
$serverPid = null;
function stopServer($process): void { proc_terminate($process, 9); proc_close($process); }
function startServer($root) {
    $process = proc_open([PHP_BINARY, __FILE__, '--server', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
    if (!$process) { throw new RuntimeException('Cannot launch fixture.'); }
    for ($round = 0; $round < 100; $round++) {
        try { zfsas_coordinator_request(['action' => 'status'], $root . '/control.sock', .1); return $process; }
        catch (RuntimeException $e) { usleep(10000); }
    }
    stopServer($process);
    throw new RuntimeException('Fixture server failed to start: ' . file_get_contents($root . '/server.log'));
}
try {
    $serverPid = startServer($root);
    // A client that never finishes a command cannot monopolize the server.
    $slow = stream_socket_client('unix://' . $root . '/control.sock'); fwrite($slow, '{"action":');
    $start = microtime(true);
    $command = ['action' => 'submit', 'commandId' => 'stable-command', 'spec' => ['manual' => true, 'tasks' => ['task' => ['kind' => 'auto']]]];
    $receipt = zfsas_coordinator_request($command, $root . '/control.sock');
    if (!$receipt['ok'] || microtime(true) - $start > 1) { throw new RuntimeException('Partial client blocked accepted command.'); }
    fclose($slow);
    $before = file_get_contents($root . '/checkpoint.json');
    zfsas_coordinator_request(['action' => 'status'], $root . '/control.sock');
    if ($before !== file_get_contents($root . '/checkpoint.json')) { throw new RuntimeException('Status mutated journal.'); }
    stopServer($serverPid); $serverPid = null;
    $serverPid = startServer($root);
    $replayed = zfsas_coordinator_request($command, $root . '/control.sock');
    if ($receipt !== $replayed) { throw new RuntimeException('Restart repeated accepted operation.'); }
    $conflict = $command; $conflict['spec']['manual'] = false;
    if (zfsas_coordinator_request($conflict, $root . '/control.sock')['ok']) { throw new RuntimeException('Conflicting duplicate accepted.'); }
    $bad = zfsas_coordinator_request(['action' => 'unknown'], $root . '/control.sock');
    if ($bad['ok']) { throw new RuntimeException('Unknown command accepted.'); }
    $ticks = count(file($root . '/ticks'));
    usleep(200000);
    if (count(file($root . '/ticks')) > $ticks + 1) { throw new RuntimeException('Idle loop polls repeatedly.'); }
    echo "PASS: socket responsiveness with partial clients, read-only status, crash/restart duplicate submission, bounded idle deadlines\n";
} finally {
    if ($serverPid) { stopServer($serverPid); }
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
}
