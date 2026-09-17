<?php
require_once __DIR__ . '/snapshot-manager-helpers.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { zfsas_emit_marked_json(['ok' => false, 'error' => 'Use POST for snapshot actions.'], 405); }
    if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok' => false, 'error' => $error], 403); }
    $dataset = trim((string) ($_POST['dataset'] ?? ''));
    $action = $_POST['action'] ?? '';
    $rows = zfsas_sm_dataset_snapshots($dataset, $error, true);
    if ($error) { throw new RuntimeException($error); }
    $map = array_column($rows, null, 'snapshot');
    $snapshots = $_POST['snapshots'] ?? [];
    if (!is_array($snapshots)) { $snapshots = [$snapshots]; }
    if (count($snapshots) > 500) { throw new RuntimeException('Explicit lists are limited to 500 snapshots; use a batch manifest for larger selections.'); }
    if (in_array($action, ['send', 'rollback'], true) && count($snapshots) !== 1) { throw new RuntimeException('Send and Rollback require exactly one snapshot.'); }
    if (in_array($action, ['send', 'rollback'], true) && (string) ($map[$snapshots[0]]['guid'] ?? '') !== (string) ($_POST['guid'] ?? '')) { throw new RuntimeException('Snapshot identity changed. Refresh before acting.'); }
    if ($action === 'send') {
        $row = $map[$snapshots[0]] ?? null;
        if (!$row || zfsas_sm_exclusion('send', $row) !== '') { throw new RuntimeException('Snapshot is not eligible for Send.'); }
        $destination = trim((string) ($_POST['destination'] ?? ''));
        if (!zfsas_sm_is_valid_dataset_name($destination) || strpos($destination, '/') === false || $destination === $dataset || strpos($destination, $dataset . '/') === 0 || strpos($dataset, $destination . '/') === 0) { throw new RuntimeException('Choose a destination outside the source tree.'); }
        require_once __DIR__ . '/replication-submit.php';
        $receipt = zfsas_native_manual_send($row['snapshot'],(string)$row['guid'],$destination,(string)($_POST['command_id'] ?? ''));
        zfsas_emit_marked_json(['ok' => true, 'dataset' => $dataset, 'message' => 'Send submitted. Follow preparation and transfer in Activity.'] + $receipt);
    }
    $batch = zfsas_sm_new_batch($dataset, $action);
    if ($action === 'take_snapshot') {
        $name = trim((string) ($_POST['snapshot_name'] ?? ''));
        if (!zfsas_sm_is_valid_snapshot_name($name) || isset($map[$dataset . '@' . $name])) { throw new RuntimeException('Choose a valid snapshot name that does not already exist.'); }
        $batch['items'] = [['snapshot' => $dataset . '@' . $name, 'guid' => '', 'identity' => $dataset . '@' . $name . '#', 'state' => 'queued', 'candidate' => true, 'reason' => 'Create this snapshot']];
        $batch['state'] = 'review';
    } else {
        foreach (array_unique($snapshots) as $snapshot) {
            if (!isset($map[$snapshot])) { throw new RuntimeException('Snapshot no longer exists. Refresh first.'); }
            $row = $map[$snapshot];
            $batch['items'][] = ['snapshot' => $snapshot, 'guid' => $row['guid'], 'identity' => $row['identity'], 'state' => 'queued'];
        }
        zfsas_sm_batch_review($batch, $rows);
    }
    zfsas_sm_batch_store($batch);
    zfsas_emit_marked_json(zfsas_sm_batch_payload($batch));
} catch (Throwable $error) { zfsas_emit_marked_json(['ok' => false, 'error' => $error->getMessage()], 409); }
