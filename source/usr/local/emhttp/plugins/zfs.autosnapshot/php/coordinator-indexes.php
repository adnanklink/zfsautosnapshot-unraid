<?php
/** Rebuildable admission indexes; the committed journal remains authoritative. */
trait ZfsasCoordinatorIndexes
{
    private array $readyTasks = [];
    private array $activeTasks = [];
    private array $dependents = [];
    private array $indexedDependencies = [];
    private array $deadlineVersions = [];
    private ?SplPriorityQueue $deadlines = null;

    private function rebuildIndexes(): void
    {
        $this->readyTasks = []; $this->activeTasks = []; $this->dependents = []; $this->indexedDependencies = []; $this->deadlineVersions = [];
        $this->referenceNames = []; $this->referenceGuids = []; $this->indexedReferences = [];
        foreach (array_keys($this->state['references']) as $id) { $this->indexReference($id); }
        $this->deadlines = new SplPriorityQueue();
        $this->deadlines->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        foreach (array_keys($this->state['tasks']) as $id) { $this->indexTask($id); }
    }

    private function indexTask(string $id): void
    {
        unset($this->readyTasks[$id], $this->activeTasks[$id]);
        $version = ($this->deadlineVersions[$id] ?? 0) + 1;
        $this->deadlineVersions[$id] = $version;
        foreach ($this->indexedDependencies[$id] ?? [] as $dependency) {
            unset($this->dependents[$dependency][$id]);
            if (empty($this->dependents[$dependency])) { unset($this->dependents[$dependency]); }
        }
        $task = $this->state['tasks'][$id] ?? null;
        if (!$task) { unset($this->indexedDependencies[$id]); return; }
        if (in_array($task['state'], ['launching', 'running', 'stopping'], true)) { $this->activeTasks[$id] = true; }
        $this->indexedDependencies[$id] = $task['dependencies'];
        foreach ($task['dependencies'] as $dependency) { $this->dependents[$dependency][$id] = true; }
        if (!in_array($task['state'], ['queued', 'waiting', 'retry_wait'], true)) { return; }
        $run = $this->state['runs'][$task['runId']] ?? null;
        if (!$run || self::terminal($run['state']) || $run['state'] === 'canceling' || !empty($run['upgradeReviewRequired'])) { return; }
        foreach ($task['dependencies'] as $dependency) {
            if (($this->state['tasks'][$dependency]['state'] ?? '') !== 'complete') { return; }
        }
        if ($task['state'] === 'waiting' && $task['blocked'] === 'dependency'
            && ($task['parameters']['batch']['action'] ?? '') === 'delete') { return; }
        $deadline = $task['retryMonotonic'] ?? 0;
        if ($deadline > 0) { $this->deadlines->insert(['id'=>$id, 'version'=>$version], -$deadline); }
        else { $this->readyTasks[$id] = true; }
    }

    private function updateIndexes(array $put, array $remove): void
    {
        if ($this->deadlines === null) { $this->rebuildIndexes(); return; }
        foreach (array_merge(array_keys($put['references'] ?? []), $remove['references'] ?? []) as $id) { $this->indexReference($id); }
        $affected = [];
        foreach (array_merge(array_keys($put['tasks'] ?? []), $remove['tasks'] ?? []) as $id) {
            $affected[$id] = true;
            foreach ($this->dependents[$id] ?? [] as $dependent => $_) { $affected[$dependent] = true; }
        }
        foreach ($put['runs'] ?? [] as $run) {
            foreach ($run['tasks'] as $id) { $affected[$id] = true; }
        }
        foreach (array_keys($affected) as $id) { $this->indexTask($id); }
        // Lazy invalidation avoids heap deletion searches. Bound obsolete entries.
        if ($this->deadlines->count() > count($this->state['tasks']) * 2 + 1024) { $this->rebuildIndexes(); }
    }

    public function runnable(float $monotonic): array
    {
        while (!$this->deadlines->isEmpty() && -$this->deadlines->top()['priority'] <= $monotonic) {
            $entry = $this->deadlines->extract()['data'];
            if (($this->deadlineVersions[$entry['id']] ?? null) === $entry['version']) { $this->readyTasks[$entry['id']] = true; }
        }
        return array_keys($this->readyTasks);
    }
    public function activeTaskIds(): array { return array_keys($this->activeTasks); }

    public function nextDeadline(float $now): float
    {
        while (!$this->deadlines->isEmpty()) {
            $entry = $this->deadlines->top();
            if (($this->deadlineVersions[$entry['data']['id']] ?? null) !== $entry['data']['version']) { $this->deadlines->extract(); continue; }
            return min($now + 30, max($now, -$entry['priority']));
        }
        return $now + 30;
    }

}
