<?php
/** Compatibility projection: execution authority is exclusively in the journal. */
function zfsas_coordinator_project_batch(ZfsasCoordinatorState $journal, string $taskId): void
{
    $task = $journal->state['tasks'][$taskId];
    if ($task['kind'] !== 'batch') { return; }
    $legacyReview = !isset($task['items']) && !empty($task['result']['recoveryRequired']);
    if (!isset($task['items'], $task['parameters']['batch']) && !$legacyReview) { return; }
    $batch = $task['parameters']['batch'] ?? zfsas_sm_read_json_file(zfsas_sm_batch_path($task['parameters']['token']));
    if (!$batch) { return; }
    $path = zfsas_sm_batch_path($batch['token']);
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) { throw new RuntimeException('Cannot lock batch projection.'); }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) { return; }
        if ($legacyReview) {
            $batch = zfsas_sm_read_json_file($path);
            if (!$batch) { return; }
            foreach ($batch['items'] as &$item) {
                if (!in_array($item['state'], ['queued', 'running'], true)) { continue; }
                $item['state'] = 'failed'; $item['recoveryRequired'] = true;
                $item['error'] = 'Execution ownership changed. Review the unfinished selection and approve a new batch.';
            }
            unset($item);
            $batch['state'] = 'complete'; $batch['runId'] = $task['runId'];
            zfsas_sm_batch_store($batch);
            return;
        }
        $batch['runId'] = $task['runId']; $batch['items'] = [];
        foreach ($task['items'] as $id) {
            $item = $journal->state['items'][$id];
            $batch['items'][] = array_replace($item['spec'], $item['result'] ?? [], ['state'=>$item['state']]);
        }
        $run = $journal->state['runs'][$task['runId']];
        $batch['state'] = $run['state'] === 'canceled' ? 'canceled' : 'running';
        zfsas_sm_batch_reconcile($batch);
        if (zfsas_sm_read_json_file($path) !== $batch) { zfsas_sm_batch_store($batch); }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
