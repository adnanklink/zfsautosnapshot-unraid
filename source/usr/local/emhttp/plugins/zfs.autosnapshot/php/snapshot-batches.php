<?php
require_once __DIR__ . '/snapshot-cleanup.php';

function zfsas_sm_batches_dir() { return zfsas_ops_root_dir() . '/snapshot-batches'; }
function zfsas_sm_batch_path($token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) { throw new RuntimeException('Invalid batch token.'); }
    return zfsas_sm_batches_dir() . '/' . $token . '.json';
}
function zfsas_sm_batch_store(array $batch)
{
    if (!zfsas_sm_write_json_file(zfsas_sm_batch_path($batch['token']), $batch)) { throw new RuntimeException('Unable to publish batch manifest.'); }
}
function zfsas_sm_batch_lock($token)
{
    zfsas_sm_ensure_dir(zfsas_sm_batches_dir());
    $lock = fopen(zfsas_sm_batch_path($token) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Unable to lock batch.'); }
    zfsas_ops_apply_owner(zfsas_sm_batch_path($token) . '.lock');
    @chmod(zfsas_sm_batch_path($token) . '.lock', 0660);
    return $lock;
}
function zfsas_sm_new_batch($dataset, $action)
{
    if (!zfsas_sm_is_valid_dataset_name($dataset) || !in_array($action, ['delete', 'hold', 'release', 'rollback', 'take_snapshot'], true)) { throw new RuntimeException('Invalid dataset or action.'); }
    return ['token' => bin2hex(random_bytes(16)), 'dataset' => $dataset, 'action' => $action,
        'state' => 'draft', 'created' => time(), 'expires' => time() + 300, 'items' => [],
        'configRevision' => zfsas_config_revision(zfsas_sm_plugin_config_dir())];
}
function zfsas_sm_pending_snapshot_actions($dataset)
{
    $pending = [];
    foreach (glob(zfsas_sm_batches_dir() . '/*.json') ?: [] as $path) {
        $batch = zfsas_sm_read_json_file($path);
        if (!$batch || $batch['dataset'] !== $dataset || !in_array($batch['state'], ['queued', 'running', 'canceling'], true)) { continue; }
        zfsas_sm_batch_reconcile($batch);
        foreach ($batch['items'] as $item) {
            if (in_array($item['state'], ['queued', 'running', 'deleting'], true)) { $pending[$item['snapshot']]['action'] = $batch['action']; $pending[$item['snapshot']]['tokens'][] = $batch['token']; }
        }
    }
    return $pending;
}
function zfsas_sm_batch_review(array &$batch, array $rows)
{
    $map = array_column($rows, null, 'identity');
    foreach ($batch['items'] as &$item) {
        $row = $map[$item['identity']] ?? null;
        $reason = $row ? zfsas_sm_exclusion($batch['action'], $row) : 'Snapshot missing or GUID changed';
        $item['candidate'] = $reason === '';
        $item['state'] = $reason === '' ? 'queued' : 'skipped';
        $item['reason'] = $reason === '' ? 'Selected snapshot' : $reason;
    }
    unset($item);
    $batch['state'] = 'review';
}
function zfsas_sm_batch_reconcile(array &$batch)
{
    foreach ($batch['items'] as &$item) {
        if ($item['state'] !== 'deleting' || empty($item['deleteJobId'])) { continue; }
        $file = zfsas_ops_status_dir() . '/delete-results/' . $item['deleteJobId'] . '.result';
        $result = @file_get_contents($file);
        if ($result === false) { continue; }
        $parts = explode("\t", trim($result), 2);
        if (in_array($parts[0], ['completed', 'skipped', 'failed'], true)) {
            $item['state'] = $parts[0]; $item['error'] = $parts[0] === 'completed' ? '' : ($parts[1] ?? 'Delete failed');
        }
    }
    unset($item);
    if (in_array($batch['state'], ['queued', 'running'], true)) {
        $active = array_filter($batch['items'], function ($item) { return in_array($item['state'], ['queued', 'running', 'deleting'], true); });
        if (!$active) { $batch['state'] = 'complete'; }
    }
}
function zfsas_sm_batch_payload(array $batch, $page = 1)
{
    $counts = ['queued' => 0, 'completed' => 0, 'skipped' => 0, 'failed' => 0];
    $eligible = 0; $recoveryRequired = false;
    foreach ($batch['items'] as $item) {
        if (!empty($item['candidate'])) { $eligible++; }
        $recoveryRequired = $recoveryRequired || !empty($item['recoveryRequired']);
        $key = in_array($item['state'], ['running', 'deleting'], true) ? 'queued' : $item['state'];
        if (isset($counts[$key])) { $counts[$key]++; }
    }
    $pages = max(1, (int) ceil(count($batch['items']) / 100));
    $page = max(1, min($pages, (int) $page));
    return ['ok' => true, 'token' => $batch['token'], 'dataset' => $batch['dataset'], 'action' => $batch['action'],
        'runId' => $batch['runId'] ?? null, 'commandId' => 'batch-' . $batch['token'],
        'state' => $batch['state'], 'selected' => count($batch['items']), 'eligible' => $eligible,
        'counts' => $counts, 'recoveryRequired' => $recoveryRequired, 'expires' => $batch['expires'], 'page' => $page, 'pages' => $pages,
        'items' => array_slice(array_values($batch['items']), ($page - 1) * 100, 100)];
}
function zfsas_sm_start_batch_worker($dataset, $token)
{
    require_once __DIR__ . '/coordinator-client.php';
    zfsas_coordinator_ensure();
    $response = zfsas_coordinator_request(['action' => 'batch', 'token' => $token, 'dataset' => $dataset]);
    if (!$response['ok']) { throw new RuntimeException($response['error']); }
    return $response['result'];
}
function zfsas_sm_dataset_gates($dataset) { return zfsas_ops_dataset_gates($dataset); }
/** Called with the batch lock and dataset gates held by the granted worker. */
function zfsas_sm_execute_item(array &$batch, array &$item, array $map)
{
    if (!in_array($item['state'], ['queued', 'running'], true)) { return; }
    // An interrupted mutation has no committed outcome. In particular, repeating
    // rollback could discard writes made after the first rollback. Deletion uses
    // its own stable command ID and result evidence, so it can be reconciled.
    if ($item['state'] === 'running' && $batch['action'] !== 'delete') {
        $item['state'] = 'failed';
        $item['recoveryRequired'] = true;
        $item['error'] = 'Execution was interrupted before its result was saved. Review current ZFS state before approving a new batch.';
        zfsas_sm_batch_store($batch);
        return;
    }
    $item['state'] = 'running';
    $item['executionStartedAt'] = time();
    zfsas_sm_batch_store($batch); // Intent must be published before the operation.
    zfsas_sm_execute_item_action($batch, $item, $map);
    $item['executionFinishedAt'] = time();
    zfsas_sm_batch_store($batch); // Never defer successful item evidence to chunk end.
}

function zfsas_sm_execute_item_action(array &$batch, array &$item, array $map)
{
    $dataset = $batch['dataset']; $action = $batch['action'];
    $row = $map[$item['identity']] ?? null;
    if ($action === 'delete') {
        $id = 'sm-' . $batch['token'] . '-' . substr(hash('sha256', $item['identity']), 0, 16);
        $pending = $row['pendingDeleteJobId'] ?? '';
        if (is_file(zfsas_ops_status_dir() . '/delete-results/' . $id . '.result') || $pending === $id) {
            $item['deleteJobId'] = $id; $item['state'] = 'deleting'; return;
        }
    }
    if ($action === 'take_snapshot') {
        if (in_array($item['snapshot'], array_column($map, 'snapshot'), true)) { $item['state'] = 'skipped'; $item['error'] = 'Snapshot already exists'; return; }
        $command = 'zfs snapshot ' . escapeshellarg($item['snapshot']);
    } else {
        if (!$row || (string) $row['guid'] !== (string) $item['guid']) {
            $item['state'] = 'skipped'; $item['error'] = 'Snapshot missing or GUID changed'; return;
        }
        // Refresh this item's properties at the operation boundary, including holds.
        $live = zfsas_sm_exec_lines('zfs list -H -p -t snapshot -o name,creation,used,written,userrefs,guid,createtxg,clones ' . escapeshellarg($item['snapshot']), $rc);
        $tags = $row['holdTags'];
        if ($rc !== 0 || !$live) { $item['state'] = 'skipped'; $item['error'] = 'Metadata no longer available'; return; }
        $p = explode("\t", $live[0]);
        if ((int) ($p[4] ?? 0) > 0) { $tags = zfsas_sm_snapshot_hold_tags($item['snapshot']); } else { $tags = []; }
        $parsed = zfsas_sm_inventory_rows($dataset, $live, [$item['snapshot'] => $tags]);
        $row = array_merge($row, $parsed[0] ?? ['metadataComplete' => false]);
        zfsas_sm_ignore_owned_pending($row, $batch);
        $row['activeTransfer'] = zfsas_sm_dataset_has_transfer($dataset);
        if ($row['guid'] !== $item['guid']) { $item['state'] = 'skipped'; $item['error'] = 'Snapshot GUID changed'; return; }
        if (($action === 'hold' && $row['pluginHeld']) || ($action === 'release' && !$row['pluginHeld'])) {
            $item['state'] = 'completed'; $item['error'] = ''; return; // Crash recovery is idempotent.
        }
        $reason = zfsas_sm_exclusion($action, $row);
        if ($reason !== '') { $item['state'] = 'skipped'; $item['error'] = $reason; return; }
        if ($action === 'delete') {
            $id = 'sm-' . $batch['token'] . '-' . substr(hash('sha256', $item['identity']), 0, 16);
            $row['deleteJobId'] = $id;
            $row['deferWorker'] = true;
            $item['deleteJobId'] = $id;
            if (zfsas_ops_enqueue_snapshot_delete($dataset, $row, false, $error)) { $item['state'] = 'deleting'; }
            else { $item['state'] = 'failed'; $item['error'] = $error; }
            return;
        }
        if ($action === 'rollback') {
            foreach ($map as $other) {
                if ($other['createdEpoch'] > $row['createdEpoch']) { $item['state'] = 'skipped'; $item['error'] = 'Newer snapshots exist; rollback would remove unselected snapshots'; return; }
            }
            $command = 'zfs rollback ' . escapeshellarg($item['snapshot']);
        } else {
            $command = 'zfs ' . ($action === 'hold' ? 'hold' : 'release') . ' ' . escapeshellarg(ZFSAS_PLUGIN_HOLD) . ' ' . escapeshellarg($item['snapshot']);
        }
    }
    $output = []; $exit = 0;
    exec($command . ' 2>&1', $output, $exit);
    $item['state'] = $exit === 0 ? 'completed' : 'failed';
    $item['error'] = $exit === 0 ? '' : substr(implode(' ', $output), 0, 2000);
    zfsas_sm_invalidate_inventory($dataset);
}

function zfsas_sm_ignore_owned_pending(array &$row, array $batch)
{
    $id = 'sm-' . $batch['token'] . '-' . substr(hash('sha256', $row['identity']), 0, 16);
    if (($row['pendingDeleteJobId'] ?? '') === $id) { $row['pendingDelete'] = false; }
    if (empty($row['pendingDelete']) && !array_diff($row['pendingBatchTokens'] ?? [], [$batch['token']])) { $row['pendingAction'] = ''; }
}
