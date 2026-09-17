<?php
// Real daemon with a harmless worker; production paths require isolation.
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync');
require_once $plugin . '/php/coordinator-socket.php';
require_once $plugin . '/php/coordinator-state.php';
require_once $plugin . '/php/schedule-spec.php';
require_once $plugin . '/php/snapshot-manager-helpers.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function request($action, $extra = []) {
    $response = zfsas_coordinator_request(['action' => $action] + $extra);
    if (!$response['ok']) { throw new RuntimeException($response['error']); }
    return $response['result'];
}
function until($predicate): void {
    for ($i = 0; $i < 300; $i++) { if ($predicate()) { return; } usleep(20000); }
    throw new RuntimeException('Daemon fixture timed out: ' . @file_get_contents('/tmp/replan-daemon.log'));
}
$dir = '/boot/config/plugins/zfs.snapsync'; mkdir($dir, 0775, true);
$old = "DATASETS=\"tank/old:1G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"hourly\"\n";
file_put_contents($dir . '/zfs_snapsync.conf', $old);
file_put_contents($dir . '/zfs_send.conf', "SEND_SNAPSHOT_PREFIX=\"send-\"\n");
$config = zfsas_config_read_pair($dir);
$schedule = ZfsasSchedule::autoConfig($config['auto']);
$occurrence = ZfsasSchedule::occurrence($schedule, time(), ZfsasSchedule::hostTimezone(), false);
$journal = new ZfsasCoordinatorState('/tmp/zfs-snapsync-coordinator');
$receipt = $journal->submit('auto-occurrence-' . $occurrence, ['schedule' => 'auto', 'occurrence' => $occurrence,
    'revision' => $config['revision'], 'tasks' => ['snapshot' => ['kind' => 'auto', 'parameters' => [
        'revision' => $config['revision'], 'autoConfig' => $config['rawAuto'], 'sendConfig' => $config['rawSend'],
        'prefixHistory' => $config['prefixHistory'], 'scheduleSpec' => $schedule]]]], time());
unset($journal);
$new = str_replace('tank/old', 'tank/new', $old);
file_put_contents($dir . '/zfs_snapsync.conf', $new);
@mkdir('/usr/local/sbin', 0755, true);
file_put_contents('/usr/local/sbin/zfs_snapsync', '#!/bin/bash' . "\n" . 'cat "$CONFIG_FILE" >> /tmp/replan-executed' . "\n");
chmod('/usr/local/sbin/zfs_snapsync', 0755);
$process = proc_open([PHP_BINARY, $plugin . '/php/coordinator-daemon.php'], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/tmp/replan-daemon.log', 'a'], 2 => ['file', '/tmp/replan-daemon.log', 'a']], $pipes);
try {
    until(function () { try { request('status'); return true; } catch (RuntimeException $e) { return false; } });
    until(function () use ($receipt) {
        foreach (request('status')['runs'] as $run) { if ($run['id'] === $receipt['runId']) { return $run['state'] === 'complete'; } }
        return false;
    });
    check(file_get_contents('/tmp/replan-executed') === $new, 'Daemon did not execute exactly once with current settings');
    $run = request('status')['runs'][0];
    check($run['replannedFrom'] === $config['revision'], 'Daemon omitted replan evidence');
    check($run['occurrence'] === $occurrence, 'Daemon shifted schedule acceptance');
    // Hold admission while accepting a manual request, then change its settings.
    file_put_contents($dir . '/maintenance', 'test');
    $manual = request('auto', ['commandId' => 'manual-before-save']);
    file_put_contents($dir . '/zfs_snapsync.conf', str_replace('tank/new', 'tank/third', $new));
    request('reload');
    $lock = fopen('/tmp/zfs-snapsync-config-locks/' . hash('sha256', $dir) . '.lock', 'c');
    flock($lock, LOCK_EX);
    unlink($dir . '/maintenance');
    $start = microtime(true);
    $status = request('status');
    check(microtime(true) - $start < 1, 'Admission blocked socket on configuration lock');
    check(file_get_contents('/tmp/replan-executed') === $new, 'Locked configuration launched work');
    flock($lock, LOCK_UN); fclose($lock);
    until(function () use ($manual) {
        foreach (request('status')['runs'] as $run) { if ($run['id'] === $manual['runId']) { return $run['state'] === 'failed' && $run['taskStatus'][0]['attemptCount'] === 0; } }
        return false;
    });
    check(file_get_contents('/tmp/replan-executed') === $new, 'Changed manual work executed without fresh approval');
    check(request('auto', ['commandId' => 'manual-before-save']) === $manual, 'Rejected manual command lost identity');
    echo "PASS: real daemon replans untouched automatic work once, preserves acceptance, rejects changed manual approval and remains responsive during save\n";
} finally { proc_terminate($process, 9); proc_close($process); }
