<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-socket.php';
require_once __DIR__ . '/coordinator-executor.php';
require_once __DIR__ . '/coordinator-retention.php';
require_once __DIR__ . '/coordinator-auto-admission.php';
require_once __DIR__ . '/coordinator-deletion.php';
require_once __DIR__ . '/coordinator-batch.php';
require_once __DIR__ . '/schedule-spec.php';
require_once __DIR__ . '/snapshot-manager-helpers.php';

$root = '/tmp/zfs-autosnapshot-coordinator';
$runtime = '/var/run/zfs-autosnapshot-coordinator';
$configDir = '/boot/config/plugins/zfs.autosnapshot';
if (is_file($configDir . '/maintenance')) { exit(0); }
if (!is_dir($runtime)) { mkdir($runtime, 0770, true); }
$owner = fopen($runtime . '/owner.lock', 'c');
if (!$owner || !flock($owner, LOCK_EX | LOCK_NB)) { exit(0); }
$journal = new ZfsasCoordinatorState($root);
$deletion = new ZfsasCoordinatorDeletion($journal, $root);
$config = null; $nextConfigCheck = 0; $nextPrune = 0;
$loadConfig = static function () use ($configDir, &$config) {
    $pair = zfsas_config_read_pair($configDir, true);
    if ($pair === null) { return false; }
    $pair['schedule'] = ZfsasSchedule::autoConfig($pair['auto']);
    $pair['timezone'] = ZfsasSchedule::hostTimezone();
    $config = $pair; return true;
};
if (!$loadConfig()) { throw new RuntimeException('Configuration save is in progress. Retry coordinator startup.'); }
$submitAuto = static function (string $commandId, bool $manual, ?int $occurrence = null) use ($journal, $configDir, &$config): array {
    if (isset($journal->state['commands'][$commandId])) { return $journal->state['commands'][$commandId]; }
    if (is_file(zfsas_ops_control_path('paused', 'auto'))) { throw new InvalidArgumentException('Auto Snapshot is paused until Resume.'); }
    if (trim($config['auto']['DATASETS'] ?? '') === '') { throw new InvalidArgumentException('No Auto Snapshot datasets are configured.'); }
    foreach ($journal->state['runs'] as $run) {
        if (ZfsasCoordinatorState::terminal($run['state'])) { continue; }
        foreach ($run['tasks'] as $id) {
            if ($journal->state['tasks'][$id]['kind'] === 'auto') { return ['runId' => $run['id'], 'blocked' => 'schedule_active']; }
        }
    }
    return $journal->submit($commandId, ['manual' => $manual, 'schedule' => $manual ? '' : 'auto',
        'occurrence' => $occurrence, 'revision' => $config['revision'], 'tasks' => ['snapshot' => ['kind' => 'auto',
            'parameters' => ['revision' => $config['revision'],
                'autoConfig' => $config['rawAuto'], 'sendConfig' => $config['rawSend'],
                'prefixHistory' => $config['prefixHistory'], 'scheduleSpec' => $config['schedule']]]]], time());
};
$command = static function (array $task) use ($root, $configDir, $journal, $deletion): ?array {
    if (is_file($configDir . '/maintenance')) { return null; }
    if ($task['kind'] === 'delete') { return $deletion->command($task); }
    if ($task['kind'] === 'batch') {
        if (isset($task['items']) && ($task['parameters']['batch']['action'] ?? '') === 'delete') {
            $deletion->dispatchBatch($task);
            $deletion->projectBatch($task['id']);
            return null;
        }
        if (isset($task['items'])) {
            zfsas_coordinator_project_batch($journal, $task['id']);
            return [PHP_BINARY, __DIR__ . '/coordinator-batch-worker.php'];
        }
        return ['outcome'=>'validation_failure', 'reason'=>'recovery_required', 'recoveryRequired'=>true,
            'message'=>'Batch execution ownership changed. Review and approve the unfinished selection again.'];
    }
    if ($task['kind'] !== 'auto') { throw new RuntimeException('No execution adapter for task kind.'); }
    // Read both files under the shared nonblocking lock. A settings save must
    // neither launch work with mixed settings nor block cancellation requests.
    $pair = zfsas_config_read_pair($configDir, true);
    if ($pair === null) { return null; }
    $pair['schedule'] = ZfsasSchedule::autoConfig($pair['auto']);
    return zfsas_coordinator_auto_command($journal, $task, $pair, $root);
};
$outcome = static function ($task, $code) use ($configDir, $journal): array {
    if ($task['kind'] === 'delete') { return ['outcome'=>'transient_failure', 'message'=>'Deletion attempt stopped without an explicit result.', 'exitCode'=>$code]; }
    if ($task['kind'] === 'batch') {
        if (isset($task['items'])) { return in_array($code, [0, 75], true) ? $journal->itemTaskOutcome($task['id']) : ['outcome'=>'transient_failure', 'exitCode'=>$code]; }
        return ['outcome'=>'validation_failure', 'recoveryRequired'=>true,
            'message'=>'Legacy batch execution authority requires a fresh review.'];
    }
    if ($task['parameters']['revision'] !== zfsas_config_revision($configDir)) {
        return ['outcome' => 'validation_failure', 'reason' => 'configuration', 'message' => 'Configuration changed; review and submit a new run.'];
    }
    return ['outcome' => $code === 0 ? 'success' : 'transient_failure', 'exitCode' => $code];
};
$executor = new ZfsasCoordinatorExecutor($journal, $root, $runtime, $command, $outcome, [],
    static function($taskId) use ($journal, $deletion) { zfsas_coordinator_project_batch($journal, $taskId); $deletion->changed($taskId); });
// Replay persistent decisions before allowing recovery to admit another attempt.
foreach ($journal->state['runs'] as $run) {
    if (is_file(zfsas_ops_control_path('cancelled', $run['id'])) && !ZfsasCoordinatorState::terminal($run['state'])) { $executor->cancel($run['id']); }
}
$handler = static function (array $request) use ($journal, $executor, $submitAuto, $loadConfig, $deletion, &$config): array {
    $action = $request['action'] ?? '';
    if ($action === 'worker_report') {
        $response = $executor->workerReport($request);
        if (str_starts_with($request['type'] ?? '', 'item_')) { zfsas_coordinator_project_batch($journal, $request['taskId']); }
        return $response;
    }
    if ($action === 'status' || $action === 'watchdog') {
        $runs = array_values($journal->state['runs']);
        usort($runs, static fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        foreach ($runs as &$run) {
            $run['kinds'] = []; $run['taskStatus'] = []; $run['blockedReasons'] = []; $run['nextRetry'] = null; $run['recoveryRequired'] = false;
            foreach ($run['tasks'] as $id) {
                $task = $journal->state['tasks'][$id];
                $run['kinds'][] = $task['kind'];
                $run['taskStatus'][] = array_intersect_key($task, array_flip(['id','kind','dataset','state','attemptCount','retryAt','blocked','dependencies','references','progress','result']));
                if ($task['blocked'] !== '') { $run['blockedReasons'][] = $task['blocked']; }
                if ($task['retryAt'] !== null) { $run['nextRetry'] = min($run['nextRetry'] ?? PHP_INT_MAX, $task['retryAt']); }
                $run['recoveryRequired'] = $run['recoveryRequired'] || $task['blocked'] === 'recovery_required' || !empty($task['result']['recoveryRequired']);
            }
            $run['kinds'] = array_values(array_unique($run['kinds']));
            $run['blockedReasons'] = array_values(array_unique($run['blockedReasons']));
        } unset($run);
        return ['runs' => array_slice($runs, 0, 120), 'sequence' => $journal->state['sequence'],
            'autoPaused' => is_file(zfsas_ops_control_path('paused', 'auto')),
            'schedule' => ZfsasSchedule::preview(ZfsasSchedule::autoConfig($config['auto']), time(), ZfsasSchedule::hostTimezone())];
    }
    if ($action === 'reload') { if (!$loadConfig()) { throw new InvalidArgumentException('Configuration save is in progress. Retry.'); } return ['revision' => $config['revision']]; }
    if ($action === 'auto') {
        $id = $request['commandId'] ?? '';
        if (!is_string($id) || $id === '') { throw new InvalidArgumentException('Stable commandId is required.'); }
        if (!$loadConfig()) { throw new InvalidArgumentException('Configuration save is in progress. Retry with the same command ID.'); }
        return $submitAuto($id, true);
    }
    if ($action === 'delete') { return $deletion->request(); }
    if ($action === 'batch') {
        $token = $request['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) { throw new InvalidArgumentException('Invalid batch token.'); }
        $id = 'batch-' . $token;
        // Receipts survive service restarts. Never reconstruct manual authority
        // from ZFS metadata or tokens after RAM loss.
        if (isset($journal->state['commands'][$id])) { return $journal->state['commands'][$id]; }
        $batch = zfsas_sm_read_json_file(zfsas_sm_batch_path($token));
        if (!$batch || empty($batch['approvedAt']) || !in_array($batch['state'], ['queued', 'running', 'complete'], true)
            || $batch['dataset'] !== ($request['dataset'] ?? '')) { throw new InvalidArgumentException('Review this selection before submitting.'); }
        if ($batch['configRevision'] !== zfsas_config_revision(zfsas_sm_plugin_config_dir())) { throw new InvalidArgumentException('Configuration changed. Review a new selection.'); }
        $items = $batch['items']; unset($batch['items']);
        return $journal->submit($id, ['manual'=>true, 'revision'=>$batch['configRevision'], 'tasks'=>[
            'items'=>['kind'=>'batch', 'dataset'=>$batch['dataset'], 'items'=>$items,
                'parameters'=>['token'=>$token, 'revision'=>$batch['configRevision'], 'batch'=>$batch]]]], time());
    }
    if ($action === 'cancel') {
        $runId = $request['runId'] ?? '';
        $run = is_string($runId) ? ($journal->state['runs'][$runId] ?? null) : null;
        if (!$run) { throw new InvalidArgumentException('Unknown run.'); }
        foreach ($run['tasks'] as $taskId) {
            if ($journal->state['tasks'][$taskId]['kind'] !== 'auto') { throw new InvalidArgumentException('This run does not support Auto Snapshot cancellation.'); }
        }
        if (!zfsas_ops_persist_control(zfsas_ops_control_path('cancelled', $runId), $runId)
            || !zfsas_ops_persist_control(zfsas_ops_control_path('paused', 'auto'), $runId)) {
            throw new RuntimeException('Cancellation could not be synchronized to flash.');
        }
        $executor->cancel($runId);
        return ['runId' => $runId, 'cancellationCommitted' => true, 'shutdownComplete' => ZfsasCoordinatorState::terminal($journal->state['runs'][$runId]['state'])];
    }
    if ($action === 'resume') {
        foreach ($journal->state['runs'] as $run) { if ($run['state'] === 'canceling') { throw new InvalidArgumentException('Wait for verified shutdown before Resume.'); } }
        $error = null;
        if (!zfsas_ops_resume_schedule('auto', $error)) { throw new RuntimeException($error ?: 'Unable to persist Resume.'); }
        return ['resumed' => true];
    }
    throw new InvalidArgumentException('Unknown coordinator action.');
};
$calendar = [];
$tick = static function (float $now) use ($journal, $executor, $root, $loadConfig, $submitAuto, $deletion, &$config, &$nextConfigCheck, &$nextPrune, &$calendar): float {
    if ($now >= $nextConfigCheck) { $loadConfig(); $nextConfigCheck = $now + 30; }
    $schedule = $config['schedule']; $zone = $config['timezone']; $wall = time();
    $key = $config['revision'] . $zone->getName();
    if (($calendar['key'] ?? '') !== $key || $wall < ($calendar['lastWall'] ?? 0) || $wall >= ($calendar['next'] ?? PHP_INT_MAX)) {
        $calendar = ['key' => $key, 'due' => ZfsasSchedule::occurrence($schedule, $wall, $zone, false),
            'next' => ZfsasSchedule::occurrence($schedule, $wall, $zone, true)];
    }
    $calendar['lastWall'] = $wall; $due = $calendar['due'];
    if ($due !== null && !is_file(zfsas_ops_control_path('paused', 'auto')) && trim($config['auto']['DATASETS'] ?? '') !== '') {
        $submitAuto('auto-occurrence-' . $due, false, $due);
    }
    if ($now >= $nextPrune) { $journal->prune($wall); zfsas_coordinator_prune_artifacts($journal, $root, zfsas_sm_batches_dir(), $wall); $nextPrune = $now + 3600; }
    $next = $calendar['next'];
    return min($deletion->tick($now), $executor->tick($now), $nextConfigCheck, $next === null ? $now + 30 : $now + max(.1, $next - $wall));
};
$server = new ZfsasCoordinatorSocket($runtime . '/control.sock', $handler, $tick);
$server->serve();
