<?php
// All send lifecycle publications (PHP and Bash) use this lock and durable fences.
function zfsas_ops_state_lock()
{
    if (!zfsas_ops_ensure_storage_dirs()) { return false; }
    $lock = @fopen(zfsas_ops_root_dir() . '/send-state.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { return false; }
    return $lock;
}

function zfsas_ops_control_path($kind, $id)
{
    return zfsas_ops_plugin_config_dir() . '/send-control/' . $kind . '/' . zfsas_ops_sanitize_job_id_for_path($id);
}

function zfsas_ops_run_id($job)
{
    return (string) ($job['PARENT_RUN_ID'] ?? $job['JOB_ID'] ?? '');
}

function zfsas_ops_run_canceled($job)
{
    return ($job['CANCELLED_BY_USER'] ?? '') === '1'
        || is_file(zfsas_ops_control_path('cancelled', zfsas_ops_run_id($job)));
}

function zfsas_ops_write_job_file($path, $payload)
{
    $lock = zfsas_ops_state_lock();
    if (!$lock) { return false; }
    try {
        $disk = zfsas_ops_parse_job_file($path) ?: [];
        if (($payload['JOB_TYPE'] ?? '') === 'send' && zfsas_ops_run_canceled($payload)) { return false; }
        if ((int) ($disk['REVISION'] ?? 0) !== (int) ($payload['REVISION'] ?? 0)) { return false; }
        $payload['REVISION'] = (string) ((int) ($disk['REVISION'] ?? 0) + 1);
        return zfsas_ops_write_job_file_unlocked($path, $payload);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function zfsas_ops_group_members($group, $start)
{
    if ($group < 2) { return []; }
    $stat = @file_get_contents('/proc/' . $group . '/stat');
    if ($stat !== false) {
        $parts = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
        if ((string) ($parts[19] ?? '') !== (string) $start || (int) ($parts[2] ?? 0) !== $group) { return null; }
    }
    $members = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $stat = @file_get_contents($path);
        if ($stat === false) { continue; }
        $parts = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
        if (($parts[0] ?? '') !== 'Z' && (int) ($parts[2] ?? 0) === $group) {
            $members[] = (int) basename(dirname($path));
        }
    }
    return $members;
}

function zfsas_ops_cancel_send_job($jobId, &$error = null)
{
    $error = null;
    $lock = zfsas_ops_state_lock();
    if (!$lock) { $error = 'Unable to lock send state.'; return false; }
    $groups = [];
    try {
        $jobs = zfsas_ops_list_jobs(['send']);
        $selected = null;
        foreach ($jobs as $job) { if (($job['JOB_ID'] ?? '') === $jobId) { $selected = $job; break; } }
        if (!$selected || !in_array($selected['STATE'] ?? '', ['queued', 'running', 'retry_wait', 'canceling'], true)) {
            $error = 'This run is no longer active.'; return false;
        }
        $run = zfsas_ops_run_id($selected);
        $fence = zfsas_ops_control_path('cancelled', $run);
        if (!zfsas_ops_ensure_dir(dirname($fence)) || file_put_contents($fence, (string) time()) === false) {
            $error = 'Unable to persist cancellation.'; return false;
        }
        if (!empty($selected['SCHEDULE_JOB_ID'])) {
            $pause = zfsas_ops_control_path('paused', $selected['SCHEDULE_JOB_ID']);
            if (!zfsas_ops_ensure_dir(dirname($pause)) || file_put_contents($pause, $run) === false) {
                $error = 'Cancellation saved, but unable to persist the schedule pause.'; return false;
            }
        }
        foreach ($jobs as $job) {
            if (zfsas_ops_run_id($job) !== $run) { continue; }
            if (!in_array($job['STATE'] ?? '', ['queued', 'running', 'retry_wait', 'canceling'], true)) { continue; }
            if (!empty($job['WORKER_PGID'])) { $groups[(int) $job['WORKER_PGID']] = $job['WORKER_START'] ?? ''; }
            $job['STATE'] = 'canceling';
            $job['PHASE'] = 'canceling';
            $job['CANCELLED_BY_USER'] = '1';
            $job['RETRY_AT'] = '0';
            $job['LAST_MESSAGE'] = 'Cancellation saved; stopping the run.';
            $job['REVISION'] = (string) ((int) ($job['REVISION'] ?? 0) + 1);
            if (!zfsas_ops_write_job_file_unlocked($job['__path'], $job)) {
                $error = 'Cancellation saved; unable to update all job records.'; return false;
            }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    // State and schedule pause are committed before any signal is sent.
    for ($round = 0; $round < 30; $round++) {
        $remaining = false;
        foreach ($groups as $group => $start) {
            $members = zfsas_ops_group_members($group, $start);
            if ($members === null) { $error = 'Cancellation saved; worker ownership changed. Shutdown was not confirmed.'; return false; }
            foreach ($members as $pid) { $remaining = true; zfsas_ops_signal_process($pid, $round < 20 ? 15 : 9); }
        }
        if (!$remaining) { break; }
        usleep(100000);
    }
    foreach ($groups as $group => $start) {
        if (zfsas_ops_group_members($group, $start) !== []) {
            $error = 'Cancellation saved; processes are still stopping. The schedule remains paused.'; return false;
        }
    }
    $lock = zfsas_ops_state_lock();
    if (!$lock) { $error = 'Cancellation saved; status is awaiting reconciliation.'; return false; }
    try {
        foreach (zfsas_ops_list_jobs(['send']) as $job) {
            if (zfsas_ops_run_id($job) !== $run || ($job['STATE'] ?? '') !== 'canceling') { continue; }
            $job['STATE'] = 'failed';
            $job['PHASE'] = 'canceled';
            $job['WORKER_PID'] = '';
            $job['LAST_MESSAGE'] = 'Canceled; schedule paused until Resume.';
            $job['LAST_ERROR'] = 'Canceled by user.';
            $job['REVISION'] = (string) ((int) ($job['REVISION'] ?? 0) + 1);
            if (!zfsas_ops_write_job_file_unlocked($job['__path'], $job)) { $error = 'Unable to publish shutdown confirmation.'; return false; }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    return true;
}

function zfsas_ops_resume_schedule($scheduleId, &$error = null)
{
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $scheduleId)) { $error = 'Invalid schedule.'; return false; }
    $lock = zfsas_ops_state_lock();
    if (!$lock) { $error = 'Unable to lock send state.'; return false; }
    try {
        foreach (zfsas_ops_list_jobs(['send']) as $job) {
            if (($job['SCHEDULE_JOB_ID'] ?? '') === $scheduleId && ($job['STATE'] ?? '') === 'canceling') {
                $error = 'Wait for cancellation to finish before resuming.'; return false;
            }
        }
        $path = zfsas_ops_control_path('paused', $scheduleId);
        return !is_file($path) || unlink($path);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
