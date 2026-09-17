<?php
require_once __DIR__ . "/coordinator-worker-state.php";
require_once __DIR__ . "/coordinator-journal.php";
require_once __DIR__ . "/coordinator-indexes.php";
require_once __DIR__ . "/coordinator-items.php";
/** Single-writer, boot-local coordinator state. Never place this under /boot. */
final class ZfsasCoordinatorState
{
    use ZfsasCoordinatorWorkerState, ZfsasCoordinatorJournal, ZfsasCoordinatorIndexes, ZfsasCoordinatorItems;
    private string $root;
    private $lock;
    public array $state;

    public function __construct(string $root)
    {
        if (!str_starts_with($root, '/tmp/') || is_link($root)) {
            throw new RuntimeException('Coordinator state must use a regular RAM directory under /tmp.');
        }
        if (!is_dir($root) && !mkdir($root, 0770, true)) { throw new RuntimeException('Cannot create coordinator state.'); }
        $this->root = $root;
        $this->lock = fopen($root . '/owner.lock', 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Coordinator already owns this journal.'); }
        $this->loadJournal();
        $this->rebuildIndexes();
    }

    private static function identifier(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $id)) { throw new InvalidArgumentException('Invalid operation identifier.'); }
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as &$item) { if (is_array($item)) { $item = self::canonical($item); } }
        unset($item);
        return $value;
    }

    public function submit(string $command, array $spec, int $now): array
    {
        self::identifier($command);
        $fingerprint = hash('sha256', json_encode(self::canonical($spec), JSON_THROW_ON_ERROR));
        if (isset($this->state['commands'][$command])) {
            $existing = $this->state['commands'][$command];
            if (!hash_equals($existing['fingerprint'], $fingerprint)) { throw new InvalidArgumentException('Command ID already has different parameters.'); }
            return $existing;
        }
        $schedule = $spec['schedule'] ?? '';
        if ($schedule !== '') {
            self::identifier($schedule);
            foreach ($this->state['runs'] as $run) {
                if ($run['schedule'] === $schedule && !self::terminal($run['state'])) {
                    return ['runId' => $run['id'], 'blocked' => 'schedule_active'];
                }
            }
            $occurrence = $spec['occurrence'] ?? null;
            if (!is_int($occurrence)) { throw new InvalidArgumentException('Scheduled runs require an occurrence.'); }
            if (($this->state['schedules'][$schedule]['accepted'] ?? PHP_INT_MIN) >= $occurrence) {
                return ['runId' => $this->state['schedules'][$schedule]['runId'] ?? null, 'blocked' => 'occurrence_accepted'];
            }
        }
        if (empty($spec['tasks']) || !is_array($spec['tasks'])) { throw new InvalidArgumentException('A run requires tasks.'); }
        $runId = 'run-' . bin2hex(random_bytes(12));
        $tasks = [];
        foreach ($spec['tasks'] as $name => $task) {
            self::identifier((string) $name);
            if (!is_array($task) || !in_array($task['kind'] ?? '', ['auto', 'send', 'prepare', 'delete', 'batch', 'finalize'], true)) {
                throw new InvalidArgumentException('Unsupported task kind.');
            }
            self::checkedItems($task);
            $tasks[$name] = $task;
        }
        // Reject missing edges and cycles before accepting any execution authority.
        $visiting = []; $visited = [];
        $visit = static function ($name) use (&$visit, &$visiting, &$visited, $tasks) {
            if (isset($visited[$name])) { return; }
            if (isset($visiting[$name]) || !isset($tasks[$name])) { throw new InvalidArgumentException('Invalid task dependency graph.'); }
            $visiting[$name] = true;
            foreach ($tasks[$name]['dependencies'] ?? [] as $dependency) { $visit($dependency); }
            unset($visiting[$name]); $visited[$name] = true;
        };
        foreach (array_keys($tasks) as $name) { $visit($name); }
        $ids = array_map(fn($name) => $runId . ':' . $name, array_keys($tasks));
        foreach ($tasks as $name => $task) {
            $id = $runId . ':' . $name;
            $this->state['tasks'][$id] = ['id' => $id, 'runId' => $runId, 'kind' => $task['kind'],
                'parameters' => $task['parameters'] ?? [], 'dataset' => $task['dataset'] ?? '',
                'dependencies' => array_map(fn($dep) => $runId . ':' . $dep, $task['dependencies'] ?? []),
                'references' => $task['references'] ?? [], 'state' => 'queued', 'attemptCount' => 0,
                'attempt' => null, 'retryAt' => null, 'retryMonotonic' => null, 'blocked' => '', 'result' => null];
            if (isset($task['items'])) { $this->registerItems($id, $task['items']); }
        }
        $this->state['runs'][$runId] = ['id' => $runId, 'commandId' => $command, 'schedule' => $schedule,
            'occurrence' => $spec['occurrence'] ?? null, 'revision' => $spec['revision'] ?? '',
            'manual' => (bool) ($spec['manual'] ?? false), 'createdAt' => $now, 'finishedAt' => null,
            'state' => 'queued', 'tasks' => $ids];
        $receipt = ['runId' => $runId, 'commandId' => $command, 'fingerprint' => $fingerprint];
        $this->state['commands'][$command] = $receipt;
        if ($schedule !== '') { $this->state['schedules'][$schedule] = ['accepted' => $spec['occurrence'], 'runId' => $runId]; }
        $this->commit();
        return $receipt;
    }

    public static function terminal(string $state): bool { return in_array($state, ['complete', 'failed', 'canceled'], true); }

    /** Upgrade invalidates manual authority, but active ownership survives until stopped. */
    public function requireUpgradeReview(int $now): void
    {
        foreach ($this->state['runs'] as $run) {
            if (empty($run['upgradeReviewRequired']) || self::terminal($run['state'])) { continue; }
            foreach ($run['tasks'] as $id) {
                $task =& $this->state['tasks'][$id];
                if (self::terminal($task['state']) || in_array($task['state'], ['launching', 'running', 'stopping'], true)) { continue; }
                $task['state'] = 'failed'; $task['blocked'] = 'recovery_required';
                $task['result'] = ['outcome' => 'validation_failure', 'recoveryRequired' => true,
                    'message' => 'Coordinator ownership upgraded. Review and approve the remaining manual work again.'];
            }
            unset($task);
            $this->settle($run['id'], $now);
        }
    }

    /** Only untouched automatic work may adopt new settings without approval. */
    public function replanAuto(string $taskId, string $revision, array $parameters, int $now): bool
    {
        $task = $this->state['tasks'][$taskId] ?? null;
        if (!$task) { return false; }
        $run = $this->state['runs'][$task['runId']];
        if ($task['kind'] !== 'auto' || $run['manual'] || $run['schedule'] !== 'auto'
            || count($run['tasks']) !== 1 || $run['state'] !== 'queued'
            || $task['state'] !== 'queued' || $task['attempt'] !== null) { return false; }
        // Recovering an attempt is not evidence that its mutations never began.
        // This also protects journals written before replanning was introduced.
        foreach ($this->state['attempts'] as $attempt) {
            if ($attempt['taskId'] === $taskId) { return false; }
        }
        if ($revision === $run['revision']) { return true; }
        $this->state['runs'][$run['id']]['replannedFrom'] ??= $run['revision'];
        $this->state['runs'][$run['id']]['replannedAt'] = $now;
        $this->state['runs'][$run['id']]['revision'] = $revision;
        $this->state['tasks'][$taskId]['parameters'] = $parameters;
        // Command identity and occurrence acceptance do not change on replanning.
        $this->commit();
        return true;
    }

    /** Admission failures do not need a worker or a destructive validation retry. */
    public function rejectAdmission(string $taskId, array $result, float $monotonic, int $now): void
    {
        if (($result['outcome'] ?? '') !== 'validation_failure'
            || !in_array($taskId, $this->runnable($monotonic), true)) {
            throw new InvalidArgumentException('Only runnable work can fail admission validation.');
        }
        $task =& $this->state['tasks'][$taskId];
        $task['state'] = 'failed'; $task['result'] = $result;
        $task['blocked'] = $result['reason'] ?? '';
        $task['retryAt'] = null; $task['retryMonotonic'] = null;
        $this->settle($task['runId'], $now);
        $this->commit();
    }

    public function claim(string $taskId, float $monotonic, int $now, string $generation = ''): string
    {
        if (!in_array($taskId, $this->runnable($monotonic), true)) { throw new InvalidArgumentException('Task is not runnable.'); }
        $token = bin2hex(random_bytes(24));
        $task =& $this->state['tasks'][$taskId];
        $task['attempt'] = $token; $task['state'] = 'launching'; $task['blocked'] = '';
        $this->state['attempts'][$token] = ['token' => $token, 'taskId' => $taskId, 'state' => 'launching', 'pid' => null, 'start' => null, 'createdAt' => $now, 'generation' => $generation];
        $this->state['runs'][$task['runId']]['state'] = 'running';
        $this->commit();
        return $token;
    }

    public function owned(string $taskId, string $token): bool
    {
        $task = $this->state['tasks'][$taskId] ?? [];
        return isset($task['attempt']) && hash_equals($task['attempt'], $token)
            && in_array($task['state'], ['launching', 'running'], true)
            && ($this->state['runs'][$task['runId']]['state'] ?? '') !== 'canceling';
    }

    public function started(string $taskId, string $token, int $pid, string $start): bool
    {
        if (!$this->owned($taskId, $token) || $pid < 2 || !ctype_digit($start)) { return false; }
        $this->state['attempts'][$token]['pid'] = $pid;
        $this->state['attempts'][$token]['start'] = $start;
        $this->state['attempts'][$token]['state'] = 'running';
        $this->state['tasks'][$taskId]['state'] = 'running';
        $this->commit(); return true;
    }

    public function result(string $taskId, string $token, array $result, float $monotonic, int $now, bool $stopped): bool
    {
        if (!$stopped || !$this->owned($taskId, $token)) { return false; }
        $outcome = $result['outcome'] ?? '';
        if (!in_array($outcome, ['success', 'transient_failure', 'validation_failure', 'wait'], true)) { throw new InvalidArgumentException('Explicit task outcome required.'); }
        if ($outcome === 'wait' && !in_array($result['reason'] ?? '', ['dependency', 'resource', 'array', 'configuration', 'space'], true)) {
            throw new InvalidArgumentException('Explicit wait reason required.');
        }
        $this->recoverItems($taskId, $token, $now);
        if (isset($this->state['tasks'][$taskId]['items']) && $outcome !== 'transient_failure') { $result = $this->itemTaskOutcome($taskId); $outcome = $result['outcome']; }
        $task =& $this->state['tasks'][$taskId];
        $task['result'] = $result; $task['attempt'] = null;
        $this->state['attempts'][$token]['state'] = 'stopped';
        if ($outcome === 'wait') {
            $reason = $result['reason'] ?? '';
            $task['state'] = 'waiting'; $task['blocked'] = $reason;
            $delay = max(1, min(30, (int) ($result['delay'] ?? 30)));
        } elseif ($outcome === 'success') {
            $task['state'] = 'complete'; $delay = 0;
        } else {
            $task['attemptCount']++;
            if ($outcome === 'transient_failure' && $task['attemptCount'] < 3) {
                $delay = [1 => 60, 2 => 300][$task['attemptCount']]; $task['state'] = 'retry_wait';
            } else { $task['state'] = 'failed'; $delay = 0; }
        }
        $task['retryAt'] = $delay ? $now + $delay : null;
        $task['retryMonotonic'] = $delay ? $monotonic + $delay : null;
        $this->settle($task['runId'], $now);
        $this->commit(); return true;
    }

    private function settle(string $runId, int $now): void
    {
        $run =& $this->state['runs'][$runId];
        $failed = false; $allComplete = true; $active = false;
        foreach ($run['tasks'] as $id) {
            $state = $this->state['tasks'][$id]['state'] ?? 'missing';
            $failed = $failed || $state === 'failed';
            $allComplete = $allComplete && $state === 'complete';
            $active = $active || in_array($state, ['launching', 'running', 'stopping'], true);
        }
        if ($failed) {
            foreach ($run['tasks'] as $id) {
                if (in_array($this->state['tasks'][$id]['state'], ['queued', 'waiting', 'retry_wait'], true)) {
                    $this->state['tasks'][$id]['state'] = 'canceled';
                }
            }
        }
        if (!$active && ($failed || $allComplete)) {
            $run['state'] = $failed ? 'failed' : 'complete'; $run['finishedAt'] = $now;
        }
    }

    /** Runs with exclusive execution authority inherited from this owner. */
    public function ownedRuns(string $runId): array
    {
        $owned = [];
        foreach ($this->state['tasks'] as $task) {
            if (($task['parameters']['ownerRunId'] ?? '') === $runId) { $owned[$task['runId']] = true; }
        }
        return array_keys($owned);
    }

    private function finishCancellation(string $runId, int $now): void
    {
        $run =& $this->state['runs'][$runId];
        if ($run['state'] !== 'canceling') { return; }
        foreach ($run['tasks'] as $id) {
            if (in_array($this->state['tasks'][$id]['state'], ['launching', 'running', 'stopping'], true)) { return; }
        }
        foreach ($this->ownedRuns($runId) as $id) {
            if (!self::terminal($this->state['runs'][$id]['state'])) { return; }
        }
        $run['state'] = 'canceled'; $run['finishedAt'] = $now;
        foreach ($run['tasks'] as $id) {
            $parent = $this->state['tasks'][$id]['parameters']['ownerRunId'] ?? '';
            if ($parent !== '' && isset($this->state['runs'][$parent])) { $this->finishCancellation($parent, $now); }
        }
    }

    // Persistent pause/cancel decisions must be synchronized by the caller first.
    public function cancel(string $runId, int $now): array
    {
        if (!isset($this->state['runs'][$runId])) { throw new InvalidArgumentException('Unknown run.'); }
        $run =& $this->state['runs'][$runId];
        if (self::terminal($run['state'])) { return []; }
        $run['state'] = 'canceling'; $tokens = [];
        foreach ($run['tasks'] as $id) {
            $task =& $this->state['tasks'][$id];
            foreach ($task['items'] ?? [] as $itemId) {
                if ($this->state['items'][$itemId]['state'] !== 'queued') { continue; }
                $this->state['items'][$itemId]['state'] = 'failed';
                $this->state['items'][$itemId]['finishedAt'] = $now;
                $this->state['items'][$itemId]['result'] = ['state'=>'failed',
                    'error'=>'Canceled before execution. Review a new selection to retry.'];
            }
            if (in_array($task['state'], ['launching', 'running', 'stopping'], true)) {
                $task['state'] = 'stopping'; $tokens[] = $task['attempt'];
            } elseif (!self::terminal($task['state'])) { $task['state'] = 'canceled'; }
        }
        foreach ($this->ownedRuns($runId) as $child) { $tokens = array_merge($tokens, $this->cancel($child, $now)); }
        $this->finishCancellation($runId, $now);
        $this->commit(); return $tokens;
    }

    public function stopped(string $token, int $now, float $monotonic): void
    {
        if (!isset($this->state['attempts'][$token])) { throw new InvalidArgumentException('Unknown attempt.'); }
        $attempt =& $this->state['attempts'][$token];
        $task =& $this->state['tasks'][$attempt['taskId']];
        if ($task['attempt'] !== $token) { return; }
        $this->recoverItems($task['id'], $token, $now);
        $attempt['state'] = 'stopped'; $task['attempt'] = null;
        $run =& $this->state['runs'][$task['runId']];
        if ($run['state'] === 'canceling') {
            $task['state'] = 'canceled';
            $this->finishCancellation($run['id'], $now);
        } elseif (!empty($run['upgradeReviewRequired'])) {
            $task['state'] = 'failed'; $task['blocked'] = 'recovery_required';
            $task['result'] = ['outcome' => 'validation_failure', 'recoveryRequired' => true,
                'message' => 'Coordinator ownership upgraded. Worker shutdown verified; fresh approval is required.'];
            $this->settle($run['id'], $now);
        } else {
            $task['state'] = 'queued'; $task['retryAt'] = $now; $task['retryMonotonic'] = $monotonic;
        }
        $this->commit();
    }

    public function runRequiresReview(string $runId): bool
    {
        foreach ($this->state['runs'][$runId]['tasks'] as $id) {
            $task = $this->state['tasks'][$id];
            if (!empty($task['result']['recoveryRequired']) || $task['blocked'] === 'recovery_required') { return true; }
            foreach ($task['items'] ?? [] as $itemId) {
                if (!empty($this->state['items'][$itemId]['result']['recoveryRequired'])) { return true; }
            }
        }
        return false;
    }

    public function prune(int $now): void
    {
        $terminal = array_filter($this->state['runs'], fn($run) => self::terminal($run['state']));
        uasort($terminal, fn($a, $b) => $b['finishedAt'] <=> $a['finishedAt']);
        $changed = false; $count = 0;
        foreach ($terminal as $id => $run) {
            if ($this->runRequiresReview($id)) { continue; }
            // A child deletion result remains evidence for its unfinished owner.
            foreach ($run['tasks'] as $taskId) {
                $ownerId = $this->state['tasks'][$taskId]['parameters']['ownerRunId'] ?? '';
                if ($ownerId !== '' && isset($this->state['runs'][$ownerId])
                    && (!self::terminal($this->state['runs'][$ownerId]['state']) || $this->runRequiresReview($ownerId))) { continue 2; }
            }
            if (++$count <= 1000 && $run['finishedAt'] >= $now - 30 * 86400) { continue; }
            foreach ($run['tasks'] as $task) {
                foreach ($this->state['attempts'] as $token => $attempt) {
                    if ($attempt['taskId'] === $task) { unset($this->state['attempts'][$token]); }
                }
                foreach ($this->state['plans'] as $key => $plan) {
                    if (($plan['taskId'] ?? '') === $task) { unset($this->state['plans'][$key]); }
                }
                foreach ($this->state['tasks'][$task]['items'] ?? [] as $itemId) { unset($this->state['items'][$itemId]); }
                unset($this->state['tasks'][$task]);
            }
            // Keep compact command receipts for idempotency for this entire boot.
            unset($this->state['runs'][$id]); $changed = true;
        }
        if ($changed) { $this->commit(); }
    }
}
