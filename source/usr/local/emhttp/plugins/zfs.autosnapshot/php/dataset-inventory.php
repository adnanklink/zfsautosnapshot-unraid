<?php
require_once __DIR__ . '/snapshot-manager-helpers.php';
$datasets = zfsas_sm_list_datasets($error);
if ($error) { zfsas_emit_marked_json(['ok' => false, 'error' => $error], 500); }
$config = zfsas_send_parse_config_file(zfsas_sm_plugin_config_dir() . '/zfs_send.conf', zfsas_send_defaults());
$jobs = zfsas_send_parse_jobs($config['SEND_JOBS'] ?? '', $errors, $warnings);
$destinations = zfsas_send_destination_datasets_from_jobs($jobs, $datasets);
zfsas_emit_marked_json(['ok' => true, 'datasets' => array_map(function ($dataset) use ($destinations) {
    return ['dataset' => $dataset, 'pool' => explode('/', $dataset)[0], 'sendDestination' => isset($destinations[$dataset])];
}, $datasets)]);
