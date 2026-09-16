<?php
// Actual daemon/API fixture: production paths only inside a disposable container.
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-socket.php';
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
$dir = '/boot/config/plugins/zfs.autosnapshot'; @mkdir($dir, 0775, true);
file_put_contents($dir . '/zfs_autosnapshot.conf', "DATASETS=\"tank/data:1G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"disabled\"\n");
file_put_contents($dir . '/zfs_send.conf', "SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/usr/local/sbin', 0755, true);
file_put_contents('/usr/local/sbin/zfs_autosnapshot', '#!/bin/bash' . "\n" . 'printf "%s\n" "$CONFIG_FILE" > /tmp/auto-captured-path; cat "$CONFIG_FILE" > /tmp/auto-captured-content; sleep 60 & wait' . "\n");
chmod('/usr/local/sbin/zfs_autosnapshot', 0755);
$process = null;
function request($action, $extra = []) { $response = zfsas_coordinator_request(['action' => $action] + $extra); if (!$response['ok']) { throw new RuntimeException($response['error']); } return $response['result']; }
function until($predicate): void { for ($i = 0; $i < 300; $i++) { if ($predicate()) { return; } usleep(20000); } throw new RuntimeException('Daemon fixture timed out: ' . @file_get_contents('/tmp/auto-daemon.log')); }
function launch($plugin) {
    $process = proc_open([PHP_BINARY, $plugin . '/php/coordinator-daemon.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/tmp/auto-daemon.log', 'a'], 2 => ['file', '/tmp/auto-daemon.log', 'a']], $pipes);
    until(function () { try { request('status'); return true; } catch (RuntimeException $e) { return false; } });
    return $process;
}
try {
    $process = launch($plugin);
    $receipt = request('auto', ['commandId' => 'manual-auto-fixture']);
    if ($receipt !== request('auto', ['commandId' => 'manual-auto-fixture'])) { throw new RuntimeException('Manual duplicate lost identity'); }
    until(fn() => is_file('/tmp/auto-captured-content'));
    if (!str_starts_with(file_get_contents('/tmp/auto-captured-path'), '/tmp/zfs-autosnapshot-coordinator/config/')) { throw new RuntimeException('Worker did not receive captured RAM config'); }
    if (file_get_contents('/tmp/auto-captured-content') !== file_get_contents($dir . '/zfs_autosnapshot.conf')) { throw new RuntimeException('Captured configuration changed'); }
    // A save may hold its RAM lock while scheduler application is slow.
    $configLock = fopen('/tmp/zfs-autosnapshot-config-locks/' . hash('sha256', $dir) . '.lock', 'c');
    flock($configLock, LOCK_EX);
    $before = microtime(true);
    request('status');
    if (zfsas_coordinator_request(['action' => 'auto', 'commandId' => 'during-save'])['ok']) { throw new RuntimeException('Submission accepted an inconsistent config save'); }
    $cancel = request('cancel', ['runId' => $receipt['runId']]);
    if (microtime(true) - $before > 1) { throw new RuntimeException('Configuration save blocked cancellation'); }
    flock($configLock, LOCK_UN); fclose($configLock);
    if (!$cancel['cancellationCommitted'] || !is_file($dir . '/send-control/paused/auto')) { throw new RuntimeException('Cancellation acknowledged before persistent pause'); }
    until(function () use ($receipt) { foreach (request('status')['runs'] as $run) { if ($run['id'] === $receipt['runId']) { return $run['state'] === 'canceled'; } } return false; });
    proc_terminate($process, 9); proc_close($process); $process = null;
    // Complete boot-local journal loss must not lose explicit control decisions.
    $root = '/tmp/zfs-autosnapshot-coordinator';
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
    $process = launch($plugin);
    $status = request('status');
    if (!$status['autoPaused'] || $status['runs']) { throw new RuntimeException('RAM loss recreated history or lost pause'); }
    if (zfsas_coordinator_request(['action' => 'auto', 'commandId' => 'after-boot'])['ok']) { throw new RuntimeException('Paused schedule admitted work'); }
    request('resume');
    if (is_file($dir . '/send-control/paused/auto')) { throw new RuntimeException('Resume not persisted'); }
    echo "PASS: actual Auto Snapshot daemon, stable submissions, captured configuration, persistent cancellation, verified shutdown, complete RAM loss and explicit Resume\n";
} finally {
    if ($process) { proc_terminate($process, 9); proc_close($process); }
}
