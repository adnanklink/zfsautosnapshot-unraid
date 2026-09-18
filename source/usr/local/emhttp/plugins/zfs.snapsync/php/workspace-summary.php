<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/coordinator-socket.php';
require_once __DIR__ . '/send-queue-helpers.php';
require_once __DIR__ . '/migrate-datasets-helpers.php';
require_once __DIR__ . '/config-service.php';
require_once __DIR__ . '/send-schedule.php';

function zfsas_workspace_summary(): array
{
    $result = ['ok' => true, 'generatedAt' => time(), 'timezone' => ZfsasSchedule::hostTimezone()->getName(),
        'sources' => [], 'operations' => [], 'schedules' => [], 'pausedSchedules' => [], 'limited' => true];
    $terminal = ['complete', 'completed', 'failed', 'canceled', 'skipped', 'recorded'];
    try {
        $response = zfsas_coordinator_request(['action' => 'status'], '/var/run/zfs-snapsync-coordinator/control.sock', .5);
        if (!$response['ok']) { throw new RuntimeException('Coordinator status unavailable.'); }
        $result['sources']['coordinator'] = ['available' => true];
        foreach ($response['result']['runs'] ?? [] as $run) {
            $kinds = $run['kinds'] ?? [];
            $replication = in_array('send',$kinds,true) || in_array('prepare',$kinds,true) || in_array('finalize',$kinds,true);
            if (!in_array('auto', $kinds, true) && !in_array('batch', $kinds, true) && !$replication) { continue; }
            $auto = in_array('auto', $kinds, true) && empty($run['nativeReplication']);
            $details = []; $datasets = [];
            foreach ($run['taskStatus'] ?? [] as $task) {
                if (!empty($task['dataset'])) { $datasets[] = $task['dataset']; }
                if (!empty($task['result']['message'])) { $details[] = $task['result']['message']; }
            }
            $result['operations'][] = ['id' => 'coordinator:' . $run['id'], 'nativeId' => $run['id'], 'coordinator'=>true,'manual'=>$run['manual'] ?? false,'type' => $auto ? 'auto' : ($replication ? 'replication' : 'batch'),
                'title' => $auto ? 'Automatic snapshots' : ($replication ? 'Replication' : 'Snapshot batch'), 'source' => implode(', ', array_unique($datasets)),
                'destination' => '', 'state' => $run['state'], 'message' => implode(' ', array_unique($details)),
                'cleanup'=>$run['cleanup'] ?? null, 'createdAt' => $run['createdAt'], 'finishedAt' => $run['finishedAt'], 'progress' => null,
                'blocked' => $run['blockedReasons'] ?? [], 'retryAt' => $run['nextRetry'] ?? null,
                'recoveryRequired' => $run['recoveryRequired'] ?? false,
                'actions' => !in_array($run['state'], array_merge($terminal, ['canceling']), true) ? ['cancel'] : (!empty($run['canRetry']) ? ['retry'] : []),
                'url' => ($replication ? '/Settings/ZFSSnapSync?section=activity' : '/Settings/ZFSSnapSync?section=snapshots') . ($auto ? '&tab=automation' : ''),
                'logType' => $auto ? 'auto' : ($replication ? 'replication' : 'batch')];
        }
    } catch (Throwable $error) { $result['sources']['coordinator'] = ['available' => false, 'message' => 'Coordinator unavailable. Its runtime status cannot currently be verified.']; }
    try {
        $jobs = zfsas_ops_send_queue_status_payload(120, true);
        $result['sources']['replication'] = ['available' => true];
        foreach ($jobs['jobs'] as $job) {
            $actions = [];
            foreach (['canCancel' => 'cancel', 'canRetry' => 'retry', 'canClear' => 'clear_failed'] as $key => $action) { if (!empty($job[$key])) { $actions[] = $action; } }
            $result['operations'][] = ['id' => 'replication:' . $job['id'], 'nativeId' => $job['id'], 'type' => 'replication',
                'parentId' => $job['parentRunId'], 'scheduleId' => $job['scheduleId'],
                'title' => $job['typeLabel'], 'source' => $job['source'], 'destination' => $job['destination'],
                'state' => $job['stateLabel'] === 'Canceled' ? 'canceled' : $job['state'], 'stateLabel' => $job['stateLabel'],
                'message' => $job['rawMessage'], 'createdAt' => strtotime($job['requestedAt']) ?: null,
                'progress' => $job['progressVisible'] ? $job['progress'] : null, 'blocked' => [],
                'retryAt' => (int) $job['retryAt'] ?: null, 'actions' => $actions, 'recoveryRequired' => $job['recoveryRequired'],
                'url' => '/Settings/ZFSSnapSync?section=replication', 'logType' => 'replication', 'logDownloadUrl' => $job['logDownloadUrl']];
        }
    } catch (Throwable $error) { $result['sources']['replication'] = ['available' => false, 'message' => 'Replication runtime records are unavailable.']; }
    try {
        // RAM status only: no dataset inventory, folder preview or Docker probe.
        $status = zfsas_migrate_read_status();
        $state = $status['STATE'] ?? '';
        $result['sources']['migration'] = ['available' => true];
        if ($state !== '' && $state !== 'idle') {
            $active = in_array($state, ['preparing','stopping_containers','migrating','waiting_for_space','restarting_containers','retrying_container_start'], true);
            $stale = $active && !zfsas_migrate_is_pid_running((int) ($status['PID'] ?? 0));
            $result['operations'][] = ['id' => 'migration:current', 'nativeId' => 'current', 'type' => 'migration',
                'title' => 'Dataset migration', 'source' => $status['DATASET'] ?? '', 'destination' => '',
                'state' => $stale ? 'failed' : ($active ? 'running' : $state), 'stateLabel' => $stale ? 'Recovery required' : str_replace('_', ' ', $state),
                'message' => $stale ? 'Worker stopped before completion. Review the migration recovery state.' : ($status['MESSAGE'] ?? ''),
                'createdAt' => null, 'progress' => isset($status['OVERALL_PERCENT']) ? (int) $status['OVERALL_PERCENT'] : null,
                'blocked' => $state === 'waiting_for_space' ? ['space'] : [], 'retryAt' => null, 'recoveryRequired' => $stale,
                'actions' => [], 'url' => '/Settings/ZFSSnapSync?section=tools&tab=migrator', 'logType' => 'migration'];
        }
    } catch (Throwable $error) { $result['sources']['migration'] = ['available' => false, 'message' => 'Migration runtime status is unavailable.']; }
    $configDir = zfsas_ops_plugin_config_dir();
    try {
        $pair = zfsas_config_read_pair($configDir, true);
        if ($pair === null) { throw new RuntimeException('Configuration is being saved.'); }
        $zone = ZfsasSchedule::hostTimezone();
        $rows = [['id' => 'auto', 'label' => 'Automatic snapshots', 'type' => 'auto', 'spec' => ZfsasSchedule::autoConfig($pair['auto'])]];
        $errors = []; $warnings = [];
        foreach (zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? '', $errors, $warnings) as $job) {
            $rows[] = ['id' => $job['id'], 'label' => $job['source'] . ' → ' . $job['destination'], 'type' => 'replication', 'spec' => zfsas_send_schedule_spec($pair['send'], $job)];
        }
        if ($errors) { throw new RuntimeException('Invalid replication configuration.'); }
        foreach ($rows as $row) {
            $paused = is_file(zfsas_ops_control_path('paused', $row['id']));
            $preview = ZfsasSchedule::preview($row['spec'], time(), $zone);
            $result['schedules'][] = ['id' => $row['id'], 'label' => $row['label'], 'type' => $row['type'], 'paused' => $paused, 'preview' => $preview];
        }
        foreach (glob($configDir . '/send-control/paused/*') ?: [] as $path) { $result['pausedSchedules'][] = basename($path); }
        $result['sources']['configuration'] = ['available' => true];
    } catch (Throwable $error) { $result['sources']['configuration'] = ['available' => false, 'message' => 'Schedule configuration is unavailable or being saved.']; }
    usort($result['operations'], static function ($a, $b) use ($terminal) {
        return (int) in_array($a['state'], $terminal, true) <=> (int) in_array($b['state'], $terminal, true)
            ?: ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0);
    });
    $result['operations'] = array_slice($result['operations'], 0, 120);
    return $result;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { zfsas_emit_marked_json(zfsas_workspace_summary()); }
