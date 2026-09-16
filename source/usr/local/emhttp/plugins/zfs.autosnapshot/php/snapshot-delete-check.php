<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/snapshot-manager-helpers.php';
[$script, $jobId, $snapshot, $guid] = $argv + ['', '', '', ''];
if (!preg_match('/^sm-([a-f0-9]{32})-/', $jobId, $match)) { exit(1); }
$batch = zfsas_sm_read_json_file(zfsas_sm_batch_path($match[1]));
if (!$batch || empty($batch['approvedAt']) || $batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) { echo 'Batch approval or configuration changed'; exit(1); }
$found = false;
foreach ($batch['items'] as $item) { if ($item['snapshot'] === $snapshot && $item['guid'] === $guid && !empty($item['candidate'])) { $found = true; break; } }
if (!$found) { echo 'Snapshot was not approved'; exit(1); }
if (!isset($batch['cleanupMode'])) { exit(0); }
$rows = zfsas_sm_dataset_snapshots($batch['dataset'], $error, true);
if ($error) { echo $error; exit(1); }
foreach ($rows as &$row) { zfsas_sm_ignore_owned_pending($row, $batch); } unset($row);
$config = zfsas_send_parse_config_file(zfsas_sm_plugin_config_dir() . '/zfs_autosnapshot.conf', zfsas_auto_defaults());
$plan = zfsas_sm_cleanup_plan($rows, $batch['cleanupMode'], $config, time(), $batch['managedOnly']);
foreach ($plan as $item) { if ($item['snapshot'] === $snapshot && $item['guid'] === $guid && $item['candidate']) { exit(0); } }
echo 'Cleanup eligibility changed before deletion'; exit(1);
