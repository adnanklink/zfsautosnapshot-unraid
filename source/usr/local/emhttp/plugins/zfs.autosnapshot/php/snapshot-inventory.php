<?php
const ZFSAS_PLUGIN_HOLD = 'zfsas-manual';

function zfsas_sm_inventory_path($dataset)
{
    return zfsas_ops_root_dir() . '/inventory/' . hash('sha256', $dataset) . '.json';
}

function zfsas_sm_invalidate_inventory($dataset)
{
    @unlink(zfsas_sm_inventory_path($dataset));
}

function zfsas_sm_inventory_rows($dataset, array $lines, array $tags)
{
    $rows = [];
    foreach ($lines as $line) {
        $p = explode("\t", trim($line));
        if (count($p) < 2 || strpos($p[0], $dataset . '@') !== 0) { continue; }
        $name = substr($p[0], strlen($dataset) + 1);
        if (!zfsas_sm_is_valid_snapshot_name($name)) { continue; }
        $complete = count($p) >= 8;
        foreach ([1, 2, 3, 4, 5, 6] as $index) { $complete = $complete && isset($p[$index]) && ctype_digit($p[$index]); }
        $holdTags = $tags[$p[0]] ?? [];
        $complete = $complete && (int) ($p[4] ?? 0) === count($holdTags) && ($p[7] ?? '') !== '';
        $guid = (string) ($p[5] ?? '');
        $rows[] = ['dataset' => $dataset, 'snapshot' => $p[0], 'snapshotName' => $name,
            'guid' => $guid, 'identity' => $p[0] . '#' . $guid, 'createtxg' => (string) ($p[6] ?? ''),
            'createdEpoch' => (int) ($p[1] ?? 0), 'createdText' => zfsas_sm_format_utc($p[1] ?? 0),
            'usedBytes' => isset($p[2]) && ctype_digit($p[2]) ? (int) $p[2] : null,
            'writtenBytes' => isset($p[3]) && ctype_digit($p[3]) ? (int) $p[3] : null,
            'userrefs' => (int) ($p[4] ?? 0), 'held' => (int) ($p[4] ?? 0) > 0,
            'holdTags' => $holdTags, 'pluginHeld' => in_array(ZFSAS_PLUGIN_HOLD, $holdTags, true),
            'externalHoldTags' => array_values(array_diff($holdTags, [ZFSAS_PLUGIN_HOLD])),
            'clones' => ($p[7] ?? '') === '-' ? [] : explode(',', $p[7] ?? ''),
            'metadataComplete' => $complete && $guid !== '' && $guid !== '0'];
    }
    return $rows;
}

function zfsas_sm_dataset_snapshots($dataset, &$error = null, $fresh = false)
{
    $error = null;
    if (!zfsas_sm_is_valid_dataset_name($dataset)) { $error = 'Invalid dataset.'; return []; }
    $path = zfsas_sm_inventory_path($dataset);
    $cached = $fresh ? null : zfsas_sm_read_json_file($path);
    if (is_array($cached) && ($cached['dataset'] ?? '') === $dataset && ($cached['expires'] ?? 0) > microtime(true)) {
        $rows = $cached['rows'];
    } else {
        // One property inventory; holds use bounded argument lists, never one process per snapshot.
        $lines = zfsas_sm_exec_lines('zfs list -H -p -t snapshot -o name,creation,used,written,userrefs,guid,createtxg,clones -d 1 ' . escapeshellarg($dataset), $rc);
        if ($rc !== 0) { $error = 'Unable to read snapshot metadata.'; return []; }
        $held = []; $tags = [];
        foreach ($lines as $line) {
            $p = explode("\t", $line);
            if ((int) ($p[4] ?? 0) > 0) { $held[] = $p[0]; }
        }
        foreach (array_chunk($held, 200) as $chunk) {
            $holdLines = zfsas_sm_exec_lines('zfs holds -H ' . implode(' ', array_map('escapeshellarg', $chunk)), $rc);
            if ($rc !== 0) { continue; }
            foreach ($holdLines as $line) {
                $p = explode("\t", $line);
                if (count($p) >= 2) { $tags[$p[0]][] = $p[1]; }
            }
        }
        $rows = zfsas_sm_inventory_rows($dataset, $lines, $tags);
        zfsas_sm_write_json_file($path, ['dataset' => $dataset, 'expires' => microtime(true) + 5, 'rows' => $rows]);
    }
    $autoPrefix = zfsas_read_auto_snapshot_prefix(zfsas_sm_plugin_config_dir());
    $prefixes = zfsas_known_send_prefixes(zfsas_sm_plugin_config_dir());
    $deletes = zfsas_ops_delete_snapshot_map();
    $activeTransfer = zfsas_sm_dataset_has_transfer($dataset);
    $pending = zfsas_sm_pending_snapshot_actions($dataset);
    foreach ($rows as &$row) {
        $row['origin'] = $autoPrefix !== '' && strpos($row['snapshotName'], $autoPrefix) === 0 ? 'auto' : 'other';
        $row['sendProtected'] = false;
        foreach ($prefixes as $prefix) {
            if (strpos($row['snapshotName'], $prefix) === 0) { $row['sendProtected'] = true; $row['origin'] = 'send'; break; }
        }
        $row['activeTransfer'] = $activeTransfer;
        $row['pendingDelete'] = isset($deletes[$row['snapshot']]);
        $row['pendingDeleteState'] = $deletes[$row['snapshot']]['STATE'] ?? '';
        $row['pendingDeleteJobId'] = $deletes[$row['snapshot']]['JOB_ID'] ?? '';
        $row['pendingAction'] = $row['pendingDelete'] ? 'delete' : ($pending[$row['snapshot']]['action'] ?? '');
        $row['pendingBatchTokens'] = $pending[$row['snapshot']]['tokens'] ?? [];
        $row['usedText'] = $row['usedBytes'] === null ? 'Unknown' : zfsas_sm_human_bytes($row['usedBytes']);
        $row['writtenText'] = $row['writtenBytes'] === null ? 'Unknown' : zfsas_sm_human_bytes($row['writtenBytes']);
        $row['eligibility'] = [];
        foreach (['delete', 'hold', 'release', 'rollback', 'send'] as $action) { $row['eligibility'][$action] = zfsas_sm_exclusion($action, $row); }
    }
    unset($row);
    return $rows;
}

function zfsas_sm_dataset_has_transfer($dataset)
{
    foreach (zfsas_ops_list_jobs(['send']) as $job) {
        if (!in_array($job['STATE'] ?? '', ['queued', 'running', 'retry_wait', 'canceling'], true)) { continue; }
        foreach (['SOURCE_ROOT', 'DATASET', 'DESTINATION_ROOT'] as $key) {
            $root = $job[$key] ?? '';
            if ($root !== '' && ($dataset === $root || strpos($dataset, $root . '/') === 0 || strpos($root, $dataset . '/') === 0)) { return true; }
        }
    }
    return false;
}

function zfsas_sm_exclusion($action, array $row, $ignorePending = false)
{
    if (empty($row['metadataComplete'])) { return 'Incomplete metadata'; }
    if (!$ignorePending && (!empty($row['pendingDelete']) || !empty($row['pendingAction']))) { return 'Action already pending'; }
    if ($action === 'release') { return empty($row['pluginHeld']) ? 'No plugin hold; external holds are not released' : ''; }
    if ($action === 'hold') { return !empty($row['pluginHeld']) ? 'Plugin hold already exists' : ''; }
    if (in_array($action, ['delete', 'rollback'], true)) {
        if (!empty($row['held'])) { return 'Snapshot is held'; }
        if (!empty($row['clones'])) { return 'Clone dependencies'; }
        if (!empty($row['sendProtected'])) { return 'Replication checkpoint or base'; }
        if (!empty($row['activeTransfer'])) { return 'Referenced by queued or active transfer'; }
    }
    if ($action === 'send' && !empty($row['activeTransfer'])) { return 'Transfer already pending'; }
    return '';
}

function zfsas_sm_filter_rows(array $rows, array $filters, $now = null)
{
    $now = $now ?? time();
    $result = array_values(array_filter($rows, function ($row) use ($filters, $now) {
        if (($filters['search'] ?? '') !== '' && stripos($row['snapshotName'], (string) $filters['search']) === false) { return false; }
        if (($filters['prefix'] ?? '') !== '' && strpos($row['snapshotName'], (string) $filters['prefix']) !== 0) { return false; }
        if (($filters['origin'] ?? '') !== '' && $row['origin'] !== $filters['origin']) { return false; }
        foreach (['held' => 'held', 'protected' => 'sendProtected'] as $filter => $property) {
            if (($filters[$filter] ?? '') !== '' && (bool) $row[$property] !== ($filters[$filter] === 'yes')) { return false; }
        }
        if (($filters['pending'] ?? '') !== '' && (!empty($row['pendingAction'])) !== ($filters['pending'] === 'yes')) { return false; }
        foreach (['used' => 'usedBytes', 'written' => 'writtenBytes', 'age' => 'age'] as $key => $property) {
            $value = $property === 'age' ? max(0, ($now - $row['createdEpoch']) / 86400) : $row[$property];
            foreach (['min', 'max'] as $bound) {
                $raw = $filters[$key . '_' . $bound] ?? '';
                if ($raw !== '' && (!is_numeric($raw) || $value === null || ($bound === 'min' ? $value < (float) $raw : $value > (float) $raw))) { return false; }
            }
        }
        foreach (['from', 'until'] as $bound) {
            if (($filters[$bound] ?? '') === '') { continue; }
            $date = strtotime($filters[$bound] . ($bound === 'until' ? ' 23:59:59 UTC' : ' 00:00:00 UTC'));
            if ($date === false || ($bound === 'from' ? $row['createdEpoch'] < $date : $row['createdEpoch'] > $date)) { return false; }
        }
        return true;
    }));
    $sort = ['name' => 'snapshotName', 'creation' => 'createdEpoch', 'used' => 'usedBytes', 'written' => 'writtenBytes'][$filters['sort'] ?? 'creation'] ?? 'createdEpoch';
    $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 1 : -1;
    usort($result, function ($a, $b) use ($sort, $direction) {
        $order = $sort === 'snapshotName' ? strcmp($a[$sort], $b[$sort]) : ($a[$sort] <=> $b[$sort]);
        return $order ? $order * $direction : strcmp($a['identity'], $b['identity']);
    });
    return $result;
}

function zfsas_sm_page(array $rows, array $filters)
{
    $matching = zfsas_sm_filter_rows($rows, $filters);
    $size = (int) ($filters['page_size'] ?? 100);
    if (!in_array($size, [50, 100, 250], true)) { $size = 100; }
    $pages = max(1, (int) ceil(count($matching) / $size));
    $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
    return ['snapshots' => array_slice($matching, ($page - 1) * $size, $size), 'total' => count($rows), 'matching' => count($matching), 'page' => $page, 'pages' => $pages, 'pageSize' => $size];
}
