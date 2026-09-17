<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/snapshot-manager-helpers.php';
require_once __DIR__ . '/coordinator-worker-client.php';
try {
    $sequence = 1;
    $grant = zfsas_coordinator_worker_report('item_chunk', $sequence++, []);
    $batch = $grant['batch'];
    if ($batch['action'] === 'delete') { throw new RuntimeException('Deletion requires its dedicated executor.'); }
    $locks = zfsas_sm_dataset_gates($batch['dataset']);
    if ($locks === false) { exit(75); }
    try {
        $rows = zfsas_sm_dataset_snapshots($batch['dataset'], $error, true);
        $map = array_column($rows, null, 'identity');
        foreach ($grant['items'] as $approved) {
            $payload = ['itemId'=>$approved['id'], 'fingerprint'=>$approved['fingerprint']];
            zfsas_coordinator_worker_report('item_start', $sequence++, $payload);
            $item = $approved['spec'];
            if ($error || $batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) {
                $item['state'] = 'skipped'; $item['error'] = $error ?: 'Configuration changed after review';
            } else {
                zfsas_sm_execute_item_action($batch, $item, $map);
            }
            $result = array_intersect_key($item, array_flip(['state', 'error', 'recoveryRequired']));
            zfsas_coordinator_worker_report('item_result', $sequence++, $payload + ['result'=>$result]);
        }
    } finally { foreach ($locks as $lock) { fclose($lock); } }
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
