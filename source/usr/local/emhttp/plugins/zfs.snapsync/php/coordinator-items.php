<?php
/** Immutable approved items; only this journal issues mutation authority. */
trait ZfsasCoordinatorItems
{
    private static function checkedItems(array $task): void
    {
        if (!isset($task['items'])) { return; }
        if ($task['kind'] !== 'batch' || !is_array($task['items']) || !array_is_list($task['items']) || count($task['items']) > 50000) {
            throw new InvalidArgumentException('Invalid approved item list.');
        }
        $identities = [];
        foreach ($task['items'] as $item) {
            if (!is_array($item) || !is_string($item['identity'] ?? null) || $item['identity'] === ''
                || isset($identities[$item['identity']]) || !in_array($item['state'] ?? '', ['queued', 'skipped', 'completed'], true)
                || strlen(json_encode($item, JSON_THROW_ON_ERROR)) > 16384) { throw new InvalidArgumentException('Invalid or duplicate approved item.'); }
            $identities[$item['identity']] = true;
        }
    }

    private function registerItems(string $taskId, array $items): void
    {
        $ids = [];
        foreach ($items as $index => $item) {
            $id = $taskId . ':item:' . $index; $ids[] = $id;
            $this->state['items'][$id] = ['id'=>$id, 'taskId'=>$taskId, 'spec'=>$item,
                'fingerprint'=>hash('sha256', json_encode(self::canonical($item), JSON_THROW_ON_ERROR)),
                'state'=>$item['state'], 'attempt'=>null, 'result'=>null];
        }
        $this->state['tasks'][$taskId]['items'] = $ids;
    }

    private function reportItem(string $taskId, string $token, string $type, array $payload, int $now): array
    {
        $task = $this->state['tasks'][$taskId];
        if (!isset($task['items'])) { throw new InvalidArgumentException('Task has no approved item authority.'); }
        $attempt =& $this->state['attempts'][$token];
        if ($type === 'item_chunk') {
            if ($payload) { throw new InvalidArgumentException('Chunk request must not supply item selection.'); }
            if (!isset($attempt['items'])) {
                $attempt['items'] = [];
                foreach ($task['items'] as $id) {
                    if ($this->state['items'][$id]['state'] === 'queued') { $attempt['items'][] = $id; }
                    if (count($attempt['items']) === 50) { break; }
                }
            }
            return ['batch'=>$task['parameters']['batch'] ?? null, 'items'=>array_map(fn($id) => $this->state['items'][$id], $attempt['items'])];
        }
        $id = $payload['itemId'] ?? null;
        if (!is_string($id) || !in_array($id, $attempt['items'] ?? [], true)) { throw new InvalidArgumentException('Item is outside the granted chunk.'); }
        $item =& $this->state['items'][$id];
        if (!is_string($payload['fingerprint'] ?? null) || !hash_equals($item['fingerprint'], $payload['fingerprint'])) {
            throw new InvalidArgumentException('Approved item identity changed.');
        }
        if ($type === 'item_start') {
            if ($item['state'] !== 'queued' || !empty($attempt['activeItem'])) { throw new InvalidArgumentException('Item cannot start or previous item is unresolved.'); }
            $item['state'] = 'running'; $item['attempt'] = $token; $item['startedAt'] = $now;
            $attempt['activeItem'] = $id;
            return ['authorized'=>true, 'itemId'=>$id];
        }
        if ($item['state'] !== 'running' || $item['attempt'] !== $token || ($attempt['activeItem'] ?? '') !== $id) {
            throw new InvalidArgumentException('Item outcome has no current execution authority.');
        }
        $result = $payload['result'] ?? null;
        if (!is_array($result) || !in_array($result['state'] ?? '', ['completed', 'skipped', 'failed'], true)
            || array_diff(array_keys($result), ['state', 'error', 'recoveryRequired'])
            || !is_string($result['error'] ?? '') || strlen($result['error'] ?? '') > 4096
            || (isset($result['recoveryRequired']) && !is_bool($result['recoveryRequired']))) {
            throw new InvalidArgumentException('Invalid bounded item outcome.');
        }
        $item['state'] = $result['state']; $item['result'] = $result; $item['finishedAt'] = $now;
        unset($attempt['activeItem']);
        return ['itemId'=>$id];
    }

    private function recoverItems(string $taskId, string $token, int $now): void
    {
        foreach ($this->state['attempts'][$token]['items'] ?? [] as $id) {
            $item =& $this->state['items'][$id];
            if ($item['state'] !== 'running' || $item['attempt'] !== $token) { continue; }
            $item['state'] = 'failed'; $item['finishedAt'] = $now;
            $item['result'] = ['state'=>'failed', 'recoveryRequired'=>true,
                'error'=>'Execution stopped without a committed result. Review current ZFS state before approving a new batch.'];
        }
        unset($item, $this->state['attempts'][$token]['activeItem']);
    }

    /** Coordinator-only completion of a deletion dependency after verified shutdown. */
    public function deletionItemResult(string $itemId, array $result, int $now): void
    {
        $item =& $this->state['items'][$itemId];
        $task =& $this->state['tasks'][$item['taskId']];
        if (($item['result'] ?? null) === $result && $item['state'] === $result['state']) { return; }
        if (($task['parameters']['batch']['action'] ?? '') !== 'delete') { throw new InvalidArgumentException('Not a deletion batch.'); }
        $item['state'] = $result['state']; $item['result'] = $result; $item['finishedAt'] = $now;
        $this->refreshDeletionBatch($task['id'], $now);
    }

    public function refreshDeletionBatch(string $taskId, int $now): void
    {
        $task =& $this->state['tasks'][$taskId];
        if (self::terminal($task['state'])) { $this->commit(); return; }
        $queued = false; $waiting = false; $failed = false; $dependencies = [];
        foreach ($task['items'] as $id) {
            $state = $this->state['items'][$id]['state'];
            $queued = $queued || $state === 'queued'; $waiting = $waiting || $state === 'deleting';
            $failed = $failed || $state === 'failed';
            if ($state === 'deleting') { $dependencies[] = $this->state['items'][$id]['deletionTaskId']; }
        }
        $task['dependencies'] = $dependencies;
        $this->state['runs'][$task['runId']]['state'] = 'running';
        $task['retryAt'] = null; $task['retryMonotonic'] = null;
        $task['blocked'] = $waiting ? 'dependency' : '';
        $task['state'] = $waiting ? 'waiting' : ($queued ? 'queued' : ($failed ? 'failed' : 'complete'));
        if (!$waiting && !$queued) {
            $task['result'] = $this->itemTaskOutcome($taskId);
            $this->settle($task['runId'], $now);
        }
        $this->commit();
    }

    public function itemTaskOutcome(string $taskId): array
    {
        $failed = false; $recovery = false;
        foreach ($this->state['tasks'][$taskId]['items'] ?? [] as $id) {
            $item = $this->state['items'][$id];
            if (in_array($item['state'], ['queued', 'running', 'deleting'], true)) { return ['outcome'=>'wait', 'reason'=>'resource', 'delay'=>1]; }
            $failed = $failed || $item['state'] === 'failed';
            $recovery = $recovery || !empty($item['result']['recoveryRequired']);
        }
        return ['outcome'=>$failed ? 'validation_failure' : 'success', 'recoveryRequired'=>$recovery,
            'message'=>$failed ? 'Some items failed. Review a failed-only retry.' : 'All approved items have recorded outcomes.'];
    }
}
