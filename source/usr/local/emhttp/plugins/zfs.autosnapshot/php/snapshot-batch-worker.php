<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/snapshot-manager-helpers.php';
$dataset = $argv[1] ?? '';
$token = $argv[2] ?? '';
// Only an attempt granted by the coordinator may execute an approved manifest.
if (getenv('ZFSAS_COORDINATED') !== '1' || !preg_match('/^[a-f0-9]{32}$/', $token)) { exit(1); }
if (is_file(zfsas_sm_plugin_config_dir() . '/maintenance')) { exit(0); }
if (!zfsas_sm_is_valid_dataset_name($dataset)) { exit(1); }
zfsas_sm_ensure_dir(zfsas_sm_batches_dir());
$owner = fopen(zfsas_sm_batches_dir() . '/' . hash('sha256', $dataset) . '.worker', 'c');
// Contention is a coordinator wait, not a worker parked on a resource.
if (!$owner || !flock($owner, LOCK_EX | LOCK_NB)) { exit(75); }
zfsas_ops_apply_owner(zfsas_sm_batches_dir() . '/' . hash('sha256', $dataset) . '.worker');
$more = true;
while ($more && !is_file(zfsas_sm_plugin_config_dir() . '/maintenance')) {
    $more = false;
    foreach ([zfsas_sm_batch_path($token)] as $path) {
        $batch = zfsas_sm_read_json_file($path);
        if (!$batch || $batch['dataset'] !== $dataset || !in_array($batch['state'], ['queued', 'running'], true)) { continue; }
        if (empty($batch['approvedAt']) || $batch['action'] !== 'delete') { exit(1); }
        zfsas_sm_batch_reconcile($batch);
        if (!array_filter($batch['items'], static fn($item) => in_array($item['state'], ['queued', 'running'], true))) {
            if ($batch['action'] === 'delete' && $batch['state'] !== 'complete') { zfsas_ops_start_delete_queue_daemon($daemonError); }
            exit(0);
        }
        $locks = zfsas_sm_dataset_gates($dataset);
        if ($locks === false) { exit(75); }
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(75); }
        try {
            $batch = zfsas_sm_read_json_file($path);
            $batch['state'] = 'running';
            zfsas_sm_batch_reconcile($batch);
            $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
            $map = array_column($rows, null, 'identity');
            $cleanupCandidates = null;
            if (isset($batch['cleanupMode'])) {
                $config = zfsas_send_parse_config_file(zfsas_sm_plugin_config_dir() . '/zfs_autosnapshot.conf', zfsas_auto_defaults());
                // Own pending actions are not exclusions; other safety metadata remains live.
                foreach ($rows as &$row) { zfsas_sm_ignore_owned_pending($row, $batch); } unset($row);
                $plan = zfsas_sm_cleanup_plan($rows, $batch['cleanupMode'], $config, time(), $batch['managedOnly']);
                $cleanupCandidates = array_column($plan, 'candidate', 'identity');
            }
            $processed = 0;
            foreach ($batch['items'] as &$item) {
                if (!in_array($item['state'], ['queued', 'running'], true)) { continue; }
                if ($processed++ >= 50) { $more = true; break; }
                if ($error || $batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) {
                    $item['state'] = 'skipped'; $item['error'] = $error ?: 'Configuration changed after review'; continue;
                }
                if ($cleanupCandidates !== null && empty($cleanupCandidates[$item['identity']])) {
                    $item['state'] = 'skipped'; $item['error'] = 'Cleanup eligibility changed after preview'; continue;
                }
                zfsas_sm_execute_item($batch, $item, $map);
            }
            unset($item);
            zfsas_sm_batch_reconcile($batch);
            zfsas_sm_batch_store($batch);
            zfsas_sm_invalidate_inventory($dataset);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
            foreach ($locks as $held) { fclose($held); }
        }
        if ($batch['action'] === 'delete') { zfsas_ops_start_delete_queue_daemon($daemonError); }
    }
    // At most 50 items per granted attempt. The coordinator admits the next chunk.
    exit($more ? 75 : 0);
}
