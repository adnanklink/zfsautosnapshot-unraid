<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/log-helpers.php';
$paths = ['auto' => '/var/log/zfs_snapsync.last.log', 'debug' => '/var/log/zfs_snapsync.log',
    'replication' => '/var/log/zfs_snapsync_send.log', 'migration' => '/var/log/zfs_snapsync_migrate_datasets.log',
    'batch' => '/var/log/zfs_snapsync_snapshot_manager.log', 'coordinator' => '/var/log/zfs_snapsync_coordinator.log'];
$type = $_GET['type'] ?? 'auto';
if (!is_string($type) || !isset($paths[$type])) { zfsas_emit_marked_json(['ok' => false, 'error' => 'Unknown log type.'], 400); }
$path = $paths[$type];
if (!zfsas_log_is_safe_path($path)) { zfsas_emit_marked_json(['ok' => false, 'error' => 'Log path is unavailable.'], 400); }
$truncated = false;
$content = is_readable($path) ? zfsas_log_tail_file_lines($path, 200, 131072, $truncated) : '';
zfsas_emit_marked_json(['ok' => true, 'content' => $content, 'truncated' => $truncated, 'available' => is_readable($path)]);
