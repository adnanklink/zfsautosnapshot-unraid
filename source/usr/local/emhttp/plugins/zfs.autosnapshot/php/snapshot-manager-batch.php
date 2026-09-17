<?php
require_once __DIR__ . '/snapshot-manager-helpers.php';
try {
    $request = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
    $action = $request['action'] ?? 'status';
    if ($action !== 'status' || $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('Use POST for snapshot actions.'); }
        if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok' => false, 'error' => $error], 403); }
    }
    $dataset = trim((string) ($request['dataset'] ?? ''));
    if ($action === 'matching') {
        $filters = json_decode($request['filters'] ?? '{}', true) ?: [];
        $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
        if ($error) { throw new RuntimeException($error); }
        $rows = zfsas_sm_filter_rows($rows, $filters);
        $identities = array_map(function ($row) { return array_intersect_key($row, array_flip(['snapshot', 'guid', 'identity', 'metadataComplete', 'pendingDelete', 'pendingAction', 'eligibility'])); }, $rows);
        zfsas_emit_marked_json(['ok' => true, 'dataset' => $dataset, 'items' => $identities]);
    }
    if ($action === 'cleanup') {
        $mode = $request['mode'] ?? '';
        if (!in_array($mode, ['zero_change', 'retention'], true)) { throw new RuntimeException('Invalid cleanup mode.'); }
        $dir = zfsas_sm_plugin_config_dir();
        $config = zfsas_send_parse_config_file($dir . '/zfs_autosnapshot.conf', zfsas_auto_defaults());
        $send = zfsas_send_parse_config_file($dir . '/zfs_send.conf', zfsas_send_defaults());
        if (zfsas_snapshot_prefixes_conflict($config['PREFIX'], $send['SEND_SNAPSHOT_PREFIX'])) { throw new RuntimeException('Resolve the prefix conflict before cleanup.'); }
        $managedDatasets = [];
        foreach (explode(',', $config['DATASETS']) as $pair) { $managedDatasets[] = preg_replace('/:[^:]*$/', '', $pair); }
        if ($mode === 'retention' && !in_array($dataset, $managedDatasets, true)) { throw new RuntimeException('Retention cleanup requires a configured Auto Snapshot dataset.'); }
        $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
        if ($error) { throw new RuntimeException($error); }
        $batch = zfsas_sm_new_batch($dataset, 'delete');
        $batch['cleanupMode'] = $mode;
        $batch['managedOnly'] = $mode === 'retention' || ($request['managed_only'] ?? '1') !== '0';
        $batch['items'] = zfsas_sm_cleanup_plan($rows, $mode, $config, time(), $batch['managedOnly']);
        $batch['state'] = 'review';
        zfsas_sm_batch_store($batch);
        zfsas_emit_marked_json(zfsas_sm_batch_payload($batch));
    }
    $token = $request['token'] ?? '';
    if ($action === 'capture' && !in_array($request['operation'] ?? '', ['delete', 'hold', 'release'], true)) { throw new RuntimeException('Bulk actions support Delete, Add plugin hold and Release plugin hold only.'); }
    if ($action === 'capture' && $token === '') {
        $batch = zfsas_sm_new_batch($dataset, $request['operation'] ?? '');
        $token = $batch['token']; zfsas_sm_batch_store($batch);
    }
    // Polling reads atomic manifests and deletion results only. It must never
    // acquire a write lock, republish progress, or restore execution authority.
    if ($action === 'status') {
        $batch = zfsas_sm_read_json_file(zfsas_sm_batch_path($token));
        if (!$batch) { throw new RuntimeException('Batch history is unavailable. After reboot, review a new selection before executing.'); }
        if ($dataset !== '' && $dataset !== $batch['dataset']) { throw new RuntimeException('Batch dataset mismatch.'); }
        zfsas_sm_batch_reconcile($batch);
        zfsas_emit_marked_json(zfsas_sm_batch_payload($batch, $request['page'] ?? 1));
    }
    $lock = zfsas_sm_batch_lock($token);
    try {
        $batch = zfsas_sm_read_json_file(zfsas_sm_batch_path($token));
        if (!$batch) { throw new RuntimeException('Batch not found.'); }
        if ($dataset !== '' && $dataset !== $batch['dataset']) { throw new RuntimeException('Batch dataset mismatch.'); }
        $dataset = $batch['dataset'];
        zfsas_sm_batch_reconcile($batch);
        if ($action === 'capture') {
            if ($batch['state'] !== 'draft' || time() > $batch['expires']) { throw new RuntimeException('Selection is sealed or expired. Capture it again.'); }
            $items = json_decode($request['items'] ?? '[]', true);
            if (!is_array($items) || count($items) > 500) { throw new RuntimeException('Explicit lists are limited to 500 items per request.'); }
            $map = array_column($batch['items'], null, 'identity');
            foreach ($items as $item) {
                $name = $item['snapshot'] ?? ''; $guid = (string) ($item['guid'] ?? '');
                if (strpos($name, $dataset . '@') !== 0 || !zfsas_sm_is_valid_snapshot_name(substr($name, strlen($dataset) + 1)) || !ctype_digit($guid)) { throw new RuntimeException('Invalid snapshot identity.'); }
                $identity = $name . '#' . $guid;
                $map[$identity] = ['snapshot' => $name, 'guid' => $guid, 'identity' => $identity, 'state' => 'queued'];
            }
            if (count($map) > 50000) { throw new RuntimeException('Selection exceeds the per-dataset batch limit.'); }
            $batch['items'] = array_values($map);
            if (($request['seal'] ?? '') === '1') {
                $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
                if ($error) { throw new RuntimeException($error); }
                zfsas_sm_batch_review($batch, $rows);
            }
        } elseif ($action === 'submit') {
            if (is_file(zfsas_sm_plugin_config_dir() . '/maintenance')) { throw new RuntimeException('Plugin maintenance is in progress.'); }
            if ($batch['state'] === 'review') {
                if (time() > $batch['expires']) { throw new RuntimeException('Preview expired after five minutes. Preview again.'); }
                if ($batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) { throw new RuntimeException('Configuration changed. Preview again.'); }
                $batch['state'] = 'queued'; $batch['approvedAt'] = time();
                zfsas_sm_batch_store($batch);
            } elseif (!in_array($batch['state'], ['queued', 'running', 'complete'], true)) { throw new RuntimeException('Review this selection before submitting.'); }
            $receipt = zfsas_sm_start_batch_worker($dataset, $token);
            $batch['runId'] = $receipt['runId'];
        } elseif ($action === 'retry') {
            $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
            if ($error) { throw new RuntimeException($error); }
            $retry = zfsas_sm_new_batch($dataset, $batch['action']);
            $retry['items'] = array_values(array_filter($batch['items'], function ($item) { return $item['state'] === 'failed'; }));
            foreach ($retry['items'] as &$item) { unset($item['deleteJobId'], $item['error'], $item['recoveryRequired'], $item['executionStartedAt'], $item['executionFinishedAt']); } unset($item);
            foreach (['cleanupMode', 'managedOnly'] as $key) { if (isset($batch[$key])) { $retry[$key] = $batch[$key]; } }
            zfsas_sm_batch_review($retry, $rows);
            zfsas_sm_batch_store($retry); $batch = $retry;
        } elseif ($action !== 'status') { throw new RuntimeException('Unknown batch action.'); }
        zfsas_sm_batch_store($batch);
        $payload = zfsas_sm_batch_payload($batch, $request['page'] ?? 1);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    zfsas_emit_marked_json($payload);
} catch (Throwable $error) {
    zfsas_emit_marked_json(['ok' => false, 'error' => $error->getMessage()], 409);
}
