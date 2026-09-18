<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/send-queue-helpers.php';
require_once __DIR__ . '/coordinator-client.php';

$configPath = '/boot/config/plugins/zfs.snapsync/zfs_send.conf';
$defaults = [
    'SEND_SNAPSHOT_PREFIX' => 'snapsync-send-',
    'SEND_MAX_PARALLEL' => '1',
    'SEND_JOBS' => '',
];

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zfsas_emit_marked_json([
        'ok' => false,
        'error' => 'Use POST for manual ZFS send requests.',
    ], 405);
}

$csrfError = null;
if (!zfsas_validate_csrf_token($csrfError)) {
    zfsas_emit_marked_json([
        'ok' => false,
        'error' => $csrfError,
    ], 403);
}

if (!zfsas_ops_ensure_storage_dirs()) {
    zfsas_emit_marked_json([
        'ok' => false,
        'error' => 'ZFS send queue storage is unavailable.',
    ], 500);
}

$config = zfsas_send_parse_config_file($configPath, $defaults);
$warnings = [];
$errors = [];
$jobs = zfsas_send_parse_jobs($config['SEND_JOBS'] ?? '', $errors, $warnings);

if (!empty($errors)) {
    zfsas_emit_marked_json([
        'ok' => false,
        'error' => $errors[0],
    ], 400);
}

if (count($jobs) === 0) {
    zfsas_emit_marked_json([
        'ok' => false,
        'error' => 'No scheduled ZFS send jobs are configured yet.',
    ], 409);
}

$commandId=$_POST['command_id'] ?? 'manual-send-'.bin2hex(random_bytes(16));
try {
    if (!is_string($commandId) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$commandId)) { throw new InvalidArgumentException('Invalid command ID.'); }
    zfsas_coordinator_ensure();
    $response=zfsas_coordinator_request(['action'=>'replication_now','commandId'=>$commandId]);
    if (!$response['ok']) { throw new RuntimeException($response['error'] ?? 'Coordinator rejected submission.'); }
    $network=array_filter($jobs,static fn($job)=>($job['transport'] ?? 'local')!=='local');
    $kickError=null;
    $networkQueued=!$network || zfsas_ops_start_queue_kicker($kickError,['--manual-now','--network-only']);
    zfsas_emit_marked_json(['ok'=>true,'commandId'=>$commandId,'runs'=>$response['result']['runs'],
        'jobCount'=>count($jobs),'networkQueued'=>$networkQueued,
        'message'=>$networkQueued ? 'Replication requests accepted. Follow progress in Activity.' : 'Local requests accepted; network queue failed: '.$kickError]);
} catch (Throwable $error) {
    zfsas_emit_marked_json(['ok'=>false,'commandId'=>$commandId,'error'=>$error->getMessage()],503);
}
