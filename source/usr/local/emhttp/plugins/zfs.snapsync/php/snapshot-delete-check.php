<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/snapshot-manager-helpers.php';
[$script, $jobId, $snapshot, $guid] = $argv + ['', '', '', ''];
if (!preg_match('/^sm-([a-f0-9]{32})-/', $jobId, $match)) { exit(1); }
$taskId = getenv('ZFSAS_TASK_ID');
$path = getenv('ZFSAS_DELETE_APPROVAL');
$expectedPath = '/tmp/zfs-snapsync-coordinator/attempt-inputs/' . hash('sha256', (string) $taskId) . '.job.approval.json';
if (!$taskId || !$path || $path !== $expectedPath || is_link($path)) { echo 'Missing captured deletion approval'; exit(1); }
$approval = zfsas_sm_read_json_file($path);
if (!$approval || ($approval['version'] ?? null) !== 1 || ($approval['taskId'] ?? '') !== $taskId
    || ($approval['jobId'] ?? '') !== $jobId || ($approval['batch']['token'] ?? '') !== $match[1]
    || ($approval['batch']['action'] ?? '') !== 'delete') { echo 'Captured deletion approval does not match the grant'; exit(1); }
$batch = $approval['batch']; $batch['items'] = [$approval['item']];
if (!$batch || empty($batch['approvedAt']) || $batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) { echo 'Batch approval or configuration changed'; exit(1); }
$found = false;
foreach ($batch['items'] as $item) { if ($item['snapshot'] === $snapshot && $item['guid'] === $guid && !empty($item['candidate'])) { $found = true; break; } }
if (!$found) { echo 'Snapshot was not approved'; exit(1); }
$rows = zfsas_sm_dataset_snapshots($batch['dataset'], $error, true);
if ($error) { echo $error; exit(1); }
foreach ($rows as &$row) { zfsas_sm_ignore_owned_pending($row, $batch); } unset($row);
$eligible = false;
foreach ($rows as $row) {
    if ($row['snapshot'] !== $snapshot || (string) $row['guid'] !== $guid) { continue; }
    $row['activeTransfer'] = zfsas_sm_dataset_has_transfer($batch['dataset']);
    $reason = zfsas_sm_exclusion('delete', $row);
    if ($reason !== '') { echo $reason; exit(1); }
    $eligible = true; break;
}
if (!$eligible) { echo 'Snapshot metadata changed before deletion'; exit(1); }
if (!isset($batch['cleanupMode'])) { exit(0); }
$config = zfsas_send_parse_config_file(zfsas_sm_plugin_config_dir() . '/zfs_snapsync.conf', zfsas_auto_defaults());
$plan = zfsas_sm_cleanup_plan($rows, $batch['cleanupMode'], $config, time(), $batch['managedOnly']);
foreach ($plan as $item) { if ($item['snapshot'] === $snapshot && $item['guid'] === $guid && $item['candidate']) { exit(0); } }
echo 'Cleanup eligibility changed before deletion'; exit(1);
