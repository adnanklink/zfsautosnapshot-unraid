<?php
require_once __DIR__ . '/coordinator-socket.php';

function zfsas_coordinator_ensure(): void
{
    try { zfsas_coordinator_request(['action' => 'watchdog'], '/var/run/zfs-snapsync-coordinator/control.sock', .2); return; }
    catch (RuntimeException $error) {}
    if (is_file('/boot/config/plugins/zfs.snapsync/maintenance')) { throw new RuntimeException('Plugin maintenance is in progress.'); }
    $daemon = __DIR__ . '/coordinator-daemon.php';
    $detach = __DIR__ . '/../scripts/detach-worker.sh';
    exec('nohup /bin/bash ' . escapeshellarg($detach) . ' php ' . escapeshellarg($daemon) . ' >> /var/log/zfs_snapsync_coordinator.log 2>&1 < /dev/null &');
    $deadline = hrtime(true) / 1e9 + 5;
    do {
        usleep(50000);
        try { zfsas_coordinator_request(['action' => 'watchdog'], '/var/run/zfs-snapsync-coordinator/control.sock', .2); return; }
        catch (RuntimeException $error) {}
    } while (hrtime(true) / 1e9 < $deadline);
    throw new RuntimeException('Coordinator did not start. Review the coordinator log.');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $action = $argv[1] ?? 'watchdog';
        if (!in_array($action, ['watchdog', 'reload', 'auto', 'delete', 'status', 'cancel', 'resume'], true)) { throw new InvalidArgumentException('Unknown coordinator command.'); }
        if ($action !== 'status') { zfsas_coordinator_ensure(); }
        $request = ['action' => $action];
        if ($action === 'auto') { $request['commandId'] = $argv[2] ?? 'manual-auto-' . bin2hex(random_bytes(16)); }
        if ($action === 'cancel') { $request['runId'] = $argv[2] ?? ''; }
        $response = zfsas_coordinator_request($request);
        if ($action !== 'watchdog' || !$response['ok']) { echo json_encode($response, JSON_THROW_ON_ERROR) . "\n"; }
        exit($response['ok'] ? 0 : 1);
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
