<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-executor.php';
if (($argv[1] ?? '') === '--server') {
    $root = $argv[2];
    $journal = new ZfsasCoordinatorState($root);
    $recovering = array_keys(array_filter($journal->state['attempts'], fn($attempt) => $attempt['state'] !== 'stopped'));
    $executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime',
        function ($task) use ($root, $journal, $recovering) {
            foreach ($recovering as $token) {
                if ($journal->state['attempts'][$token]['state'] !== 'stopped') {
                    throw new RuntimeException('New grant before unrelated recovered worker stopped');
                }
            }
            return $task['kind'] === 'delete' ? ['/bin/true'] : ['/bin/bash', $root . '/worker.sh', $root];
        },
        fn($task, $code) => ['outcome' => $code === 0 ? 'success' : 'transient_failure']);
    $journal->submit('recovery', ['manual' => true, 'tasks' => ['one' => ['kind' => 'auto']]], time());
    if ($recovering) { $journal->submit('unrelated', ['tasks'=>['other'=>['kind'=>'delete']]], time()); }
    while (true) { $executor->tick(hrtime(true) / 1e9); usleep(20000); }
}
$root = '/tmp/zfsas-recovery-test-' . bin2hex(random_bytes(8)); mkdir($root);
file_put_contents($root . '/worker.sh', '#!/bin/bash' . "\n" . 'if mkdir "$1/first" 2>/dev/null; then sleep 60 & echo $! > "$1/child"; wait; else printf recovered > "$1/recovered"; fi' . "\n");
function launch($root) { return proc_open([PHP_BINARY, __FILE__, '--server', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes); }
function readState($root) { return ZfsasCoordinatorState::readCommitted($root); }
function until($predicate, $root): void {
    for ($round = 0; $round < 400; $round++) { if ($predicate()) { return; } usleep(20000); }
    throw new RuntimeException('Recovery fixture timed out: ' . file_get_contents($root . '/server.log'));
}
$process = null; $old = null;
try {
    $process = launch($root);
    until(fn() => is_file($root . '/child'), $root);
    $state = readState($root); $old = array_values($state['attempts'])[0];
    proc_terminate($process, 9); proc_close($process); $process = null;
    if (!ZfsasCoordinatorExecutor::members($old['pid'], $old['start'])) { throw new RuntimeException('No old worker survived fixture coordinator crash'); }
    $process = launch($root);
    until(fn() => is_file($root . '/recovered'), $root);
    if (ZfsasCoordinatorExecutor::members($old['pid'], $old['start']) !== []) { throw new RuntimeException('Reclaimed work before old group shutdown'); }
    until(function () use ($root) { $s = readState($root); return count($s['runs']) === 2 && !array_filter($s['runs'], fn($run) => $run['state'] !== 'complete'); }, $root);
    $state = readState($root);
    if (count($state['runs']) !== 2 || count($state['attempts']) !== 3) { throw new RuntimeException('Recovery lost run identity or duplicated execution'); }
    echo "PASS: coordinator SIGKILL recovery stops surviving worker and pipeline child before issuing new attempt authority\n";
} finally {
    if ($process) { proc_terminate($process, 9); proc_close($process); }
    if ($old) { foreach (ZfsasCoordinatorExecutor::members($old['pid'], $old['start']) ?? [] as $member) { exec('/bin/kill -9 ' . $member['pid'] . ' 2>/dev/null'); } }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}
