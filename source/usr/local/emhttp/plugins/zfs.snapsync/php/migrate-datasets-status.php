<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/migrate-datasets-helpers.php';

$selectedDataset = zfsas_migrate_trim($_GET['dataset'] ?? '');
if (($_GET['mode'] ?? '') === 'runtime') {
    zfsas_emit_marked_json(['ok' => true, 'selectedDataset' => $selectedDataset,
        'status' => zfsas_migrate_current_status(), 'logTail' => zfsas_migrate_status_log_tail(40)]);
    exit;
}
$datasetError = null;
$previewError = null;

$datasets = zfsas_migrate_list_datasets($datasetError);
$status = zfsas_migrate_current_status();
$statusIsLive = (bool) (($status['isActive'] ?? false) || ($status['isStale'] ?? false));
$preview = null;
if ($selectedDataset !== '' && (!$statusIsLive || zfsas_migrate_trim($status['DATASET'] ?? '') !== $selectedDataset)) {
    $preview = zfsas_migrate_preview_dataset($selectedDataset, $previewError);
}

zfsas_emit_marked_json([
    'ok' => true,
    'selectedDataset' => $selectedDataset,
    'datasetError' => $datasetError,
    'previewError' => $previewError,
    'datasets' => $datasets,
    'preview' => $preview,
    'status' => $status,
    'docker' => zfsas_migrate_docker_preflight(),
    'logTail' => zfsas_migrate_status_log_tail(40),
]);
