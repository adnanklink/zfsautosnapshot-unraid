<?php
/** Compatibility projection: execution authority is exclusively in the journal. */
function zfsas_coordinator_project_batch(ZfsasCoordinatorState $journal, string $taskId): bool
{
    $task = $journal->state['tasks'][$taskId];
    if ($task['kind'] !== 'batch') { return true; }
    $legacyReview = !isset($task['items']) && !empty($task['result']['recoveryRequired']);
    if (!isset($task['items'], $task['parameters']['batch']) && !$legacyReview) { return true; }
    $batch = $task['parameters']['batch'] ?? zfsas_sm_read_json_file(zfsas_sm_batch_path($task['parameters']['token']));
    if (!$batch) { return true; }
    zfsas_sm_ensure_dir(zfsas_sm_batches_dir());
    $path = zfsas_sm_batch_path($batch['token']);
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) { throw new RuntimeException('Cannot lock batch projection.'); }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) { return false; }
        if ($legacyReview) {
            $batch = zfsas_sm_read_json_file($path);
            if (!$batch) { return true; }
            // Keep committed deletion outcomes; only unresolved authority needs
            // fresh approval after an ownership upgrade.
            zfsas_sm_batch_reconcile($batch);
            foreach ($batch['items'] as &$item) {
                if (!in_array($item['state'], ['queued', 'running', 'deleting'], true)) { continue; }
                $item['state'] = 'failed'; $item['recoveryRequired'] = true;
                $item['error'] = 'Execution ownership changed. Review the unfinished selection and approve a new batch.';
            }
            unset($item);
            $batch['state'] = 'complete'; $batch['runId'] = $task['runId'];
            zfsas_sm_batch_store($batch);
            return true;
        }
        $batch['runId'] = $task['runId']; $batch['items'] = [];
        foreach ($task['items'] as $id) {
            $item = $journal->state['items'][$id];
            $spec = $item['spec'];
            if (isset($item['deleteJobId'])) { $spec['deleteJobId'] = $item['deleteJobId']; }
            $batch['items'][] = array_replace($spec, $item['result'] ?? [], ['state'=>$item['state']]);
        }
        $run = $journal->state['runs'][$task['runId']];
        $batch['state'] = $run['state'] === 'canceled' ? 'canceled' : 'running';
        zfsas_sm_batch_reconcile($batch);
        if (zfsas_sm_read_json_file($path) !== $batch) { zfsas_sm_batch_store($batch); }
        return true;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
