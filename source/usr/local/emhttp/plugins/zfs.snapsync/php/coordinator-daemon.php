<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-socket.php';
require_once __DIR__ . '/coordinator-executor.php';
require_once __DIR__ . '/coordinator-retention.php';
require_once __DIR__ . '/coordinator-auto-admission.php';
require_once __DIR__ . '/coordinator-deletion.php';
require_once __DIR__ . '/coordinator-replication-inspect.php';
require_once __DIR__ . '/coordinator-replication.php';
require_once __DIR__ . '/coordinator-scheduled-replication.php';
require_once __DIR__ . '/coordinator-batch.php';
require_once __DIR__ . '/schedule-spec.php';
require_once __DIR__ . '/snapshot-manager-helpers.php';

$root = '/tmp/zfs-snapsync-coordinator';
$runtime = '/var/run/zfs-snapsync-coordinator';
$configDir = '/boot/config/plugins/zfs.snapsync';
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
    if (in_array($task['parameters']['phase'] ?? '',['replication_schedule','replication_snapshot','replication_member','replication_run_verify'],true)) { return zfsas_coordinator_schedule_command($task,$journal,$root,zfsas_config_revision($configDir)); }
    if ($task['kind'] === 'delete') { return $deletion->command($task); }
    if ($task['kind'] === 'prepare' && ($task['parameters']['phase'] ?? '') === 'replication_inspect') {
        return zfsas_coordinator_replication_inspection_command($task, $root);
    }
    if (in_array($task['parameters']['phase'] ?? '', ['replication_space','replication_transfer','replication_verify'],true)) {
        return zfsas_coordinator_replication_command($task,$root,zfsas_config_revision($configDir),$journal);
    }
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
    if (!empty($task['parameters']['nativeSchedule'])) { return ['outcome'=>'transient_failure','message'=>'Scheduled task stopped without an explicit outcome.']; }
    if (in_array($task['kind'], ['send','finalize'],true)) { return ['outcome'=>'transient_failure','recoveryRequired'=>true,'message'=>'Native replication stopped without an explicit result.']; }
    if ($task['kind'] === 'prepare') { return ['outcome'=>'transient_failure','message'=>'Inspection stopped without an explicit result.']; }
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
    static function($taskId) use ($journal, $deletion) { $journal->resolveReplicationRecovery($journal->state['tasks'][$taskId]['runId']); zfsas_coordinator_project_batch($journal, $taskId); $deletion->changed($taskId); });
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
            $run['canRetry'] = $run['manual'] && in_array($run['state'],['failed','canceled'],true) && !empty($journal->state['tasks'][$run['id'].':prepare']['parameters']['replication']);
            $run['nativeReplication']=isset($journal->state['tasks'][$run['id'].':prepare']['parameters']['replication']) || !empty($journal->state['tasks'][$run['id'].':prepare']['parameters']['nativeSchedule']);
            $run['kinds'] = []; $run['taskStatus'] = []; $run['blockedReasons'] = []; $run['nextRetry'] = null; $run['recoveryRequired'] = false;
            foreach ($run['tasks'] as $id) {
                $task = $journal->state['tasks'][$id];
                $run['kinds'][] = $task['kind'];
                $run['taskStatus'][] = array_intersect_key($task, array_flip(['id','kind','dataset','state','attemptCount','retryAt','blocked','dependencies','references','progress','result']));
                if ($task['blocked'] !== '') { $run['blockedReasons'][] = $task['blocked']; }
                if ($task['retryAt'] !== null) { $run['nextRetry'] = min($run['nextRetry'] ?? PHP_INT_MAX, $task['retryAt']); }
                $run['recoveryRequired'] = $run['recoveryRequired'] || $task['blocked'] === 'recovery_required' || !empty($task['result']['recoveryRequired']);
            }
            $run['recoveryRequired']=$journal->runRequiresReview($run['id']);
            if (isset($run['recoveryResolvedBy'])) { $run['canRetry']=false; }
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
    if ($action === 'retry') {
        if (!$loadConfig()) { throw new InvalidArgumentException('Configuration save is in progress. Retry the same command.'); }
        return zfsas_coordinator_retry_replication($journal,(string)($request['runId'] ?? ''),$config['revision'],$config['send']);
    }
    if ($action === 'scheduled_replication') {
        if (!$loadConfig()) { throw new InvalidArgumentException('Configuration save is in progress.'); }
        $id=$request['scheduleId'] ?? '';
        if (!is_string($id) || is_file(zfsas_ops_control_path('paused',$id))) { throw new InvalidArgumentException('Schedule is paused or invalid.'); }
        foreach (zfsas_send_parse_jobs($config['send']['SEND_JOBS']) as $job) {
            if ($job['id']!==$id) { continue; }
            $command=$request['commandId'] ?? ''; $occurrence=$request['occurrence'] ?? null;
            if (!is_string($command)||$command===''||!is_int($occurrence)) { throw new InvalidArgumentException('Stable command and occurrence required.'); }
            return zfsas_coordinator_submit_schedule($journal,$job,$config,$occurrence,true,$command);
        }
        throw new InvalidArgumentException('Schedule no longer exists.');
    }
    if ($action === 'replication_receipt') { return zfsas_coordinator_replication_receipt($journal,$request); }
    if ($action === 'replication') {
        if (!$loadConfig()) { throw new InvalidArgumentException('Configuration save is in progress. Retry the same command.'); }
        return zfsas_coordinator_submit_replication($journal,$request,$config['revision'],$config['send']);
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
        $pauseAuto = false;
        foreach ($run['tasks'] as $taskId) {
            $kind = $journal->state['tasks'][$taskId]['kind'];
            if (!in_array($kind, ['auto', 'batch'], true) && !isset($journal->state['tasks'][$taskId]['parameters']['replication']) && empty($journal->state['tasks'][$taskId]['parameters']['nativeSchedule'])) { throw new InvalidArgumentException('Cancel this task through its owning run.'); }
            $pauseAuto = $pauseAuto || ($kind === 'auto' && empty($journal->state['tasks'][$taskId]['parameters']['nativeSchedule']));
        }
        $scheduleId=$pauseAuto ? 'auto' : ($journal->state['tasks'][$runId.':prepare']['parameters']['job']['id'] ?? '');
        // Persist pause first: a crash or failed second publication must never
        // leave a committed cancellation that permits future scheduled mutation.
        if ($scheduleId!=='' && !zfsas_ops_persist_control(zfsas_ops_control_path('paused',$scheduleId),$runId)) {
            throw new InvalidArgumentException('Cannot persist schedule pause. Retry cancellation.');
        }
        if (!zfsas_ops_persist_control(zfsas_ops_control_path('cancelled',$runId),$runId)) {
            throw new InvalidArgumentException('Cancellation could not be synchronized to flash. The schedule may already be paused; retry cancellation.');
        }
        $executor->cancel($runId);
        return ['runId' => $runId, 'cancellationCommitted' => true, 'shutdownComplete' => ZfsasCoordinatorState::terminal($journal->state['runs'][$runId]['state'])];
    }
    if ($action === 'resume') {
        foreach ($journal->state['runs'] as $run) { if ($run['state'] === 'canceling') { throw new InvalidArgumentException('Wait for verified shutdown before Resume.'); } }
        $scheduleId=$request['scheduleId'] ?? 'auto';
        if (!is_string($scheduleId) || ($scheduleId!=='auto' && !preg_match('/^[a-f0-9]{12}$/D',$scheduleId))) { throw new InvalidArgumentException('Invalid schedule identity.'); }
        $error = null;
        if (!zfsas_ops_resume_schedule($scheduleId, $error)) { throw new RuntimeException($error ?: 'Unable to persist Resume.'); }
        return ['resumed' => true,'scheduleId'=>$scheduleId];
    }
    throw new InvalidArgumentException('Unknown coordinator action.');
};
$calendar = [];
$tick = static function (float $now) use ($journal, $executor, $root, $loadConfig, $submitAuto, $deletion, &$config, &$nextConfigCheck, &$nextPrune, &$calendar): float {
    if ($now >= $nextConfigCheck) { $loadConfig(); $nextConfigCheck = $now + 30; }
    $executor->setLimits(['send'=>max(1,(int)$config['send']['SEND_MAX_PARALLEL']),'prepare'=>max(1,(int)$config['send']['SEND_PREP_EXTRA_WORKERS'])]);
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
