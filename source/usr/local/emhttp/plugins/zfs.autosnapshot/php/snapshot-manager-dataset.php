<?php
require_once __DIR__ . '/snapshot-manager-helpers.php';
$dataset = zfsas_sm_trim($_GET['dataset'] ?? '');
$rows = zfsas_sm_dataset_snapshots($dataset, $error);
if ($error !== null) { zfsas_emit_marked_json(['ok' => false, 'error' => $error, 'dataset' => $dataset], 400); }
zfsas_emit_marked_json(array_merge(['ok' => true, 'dataset' => $dataset,
    'pendingCount' => zfsas_sm_queue_pending_count($dataset),
    'status' => zfsas_sm_read_json_file(zfsas_sm_dataset_status_file($dataset))], zfsas_sm_page($rows, $_GET)));
