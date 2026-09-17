<?php
/** Worker publications are proposals; only the coordinator commits transitions. */
trait ZfsasCoordinatorWorkerState
{
    private function workerOwner(array $request, string $generation): array
    {
        $taskId = $request['taskId'] ?? '';
        $token = $request['token'] ?? '';
        if (!is_string($taskId) || !is_string($token) || !is_string($request['generation'] ?? null)
            || $generation === '' || !hash_equals($generation, $request['generation'])
            || !$this->owned($taskId, $token)
            || ($this->state['tasks'][$taskId]['state'] ?? '') !== 'running'
            || ($this->state['attempts'][$token]['generation'] ?? '') !== $generation) {
            throw new InvalidArgumentException('Worker ownership expired; stop before further work.');
        }
        return [$taskId, $token];
    }

    private static function checkedWorkerOutcome(array $result): void
    {
        if (!in_array($result['outcome'] ?? '', ['success', 'transient_failure', 'validation_failure', 'wait'], true)) {
            throw new InvalidArgumentException('Explicit worker outcome required.');
        }
        if (($result['outcome'] ?? '') === 'wait'
            && !in_array($result['reason'] ?? '', ['dependency', 'resource', 'array', 'configuration', 'space'], true)) {
            throw new InvalidArgumentException('Explicit wait reason required.');
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('Worker result exceeds the bounded response limit.');
        }
    }

    public function workerReport(array $request, string $generation, int $now): array
    {
        [$taskId, $token] = $this->workerOwner($request, $generation);
        $sequence = $request['sequence'] ?? null;
        $type = $request['type'] ?? '';
        $payload = $request['payload'] ?? null;
        if (!is_int($sequence) || $sequence < 1 || !is_array($payload)
            || !in_array($type, ['progress', 'result', 'plan'], true)) {
            throw new InvalidArgumentException('Invalid worker publication.');
        }
        $fingerprint = hash('sha256', json_encode(self::canonical(['type' => $type, 'payload' => $payload]), JSON_THROW_ON_ERROR));
        $attempt =& $this->state['attempts'][$token];
        $last = $attempt['publication'] ?? ['sequence' => 0];
        if ($sequence === $last['sequence'] && ($last['fingerprint'] ?? '') === $fingerprint) {
            return ['accepted' => true, 'sequence' => $sequence];
        }
        if ($sequence !== $last['sequence'] + 1 || isset($attempt['reportedResult'])) {
            throw new InvalidArgumentException('Out-of-order or conflicting worker publication.');
        }
        if ($type === 'progress') {
            if (array_diff(array_keys($payload), ['phase', 'message', 'percent'])
                || !is_string($payload['phase'] ?? '') || strlen($payload['phase'] ?? '') > 80
                || !is_string($payload['message'] ?? '') || strlen($payload['message'] ?? '') > 4096
                || (isset($payload['percent']) && (!is_int($payload['percent']) || $payload['percent'] < 0 || $payload['percent'] > 100))) {
                throw new InvalidArgumentException('Invalid bounded progress report.');
            }
            $this->state['tasks'][$taskId]['progress'] = $payload;
        } elseif ($type === 'result') {
            self::checkedWorkerOutcome($payload);
            // An acknowledged report is not completion. The executor must verify
            // the entire process group has stopped before consuming this result.
            $attempt['reportedResult'] = $payload;
        } else {
            $this->publishWorkerPlan($taskId, $payload, $now);
        }
        $attempt['publication'] = ['sequence' => $sequence, 'fingerprint' => $fingerprint];
        $this->commit();
        return ['accepted' => true, 'sequence' => $sequence];
    }

    private function publishWorkerPlan(string $taskId, array $plan, int $now): void
    {
        $parent = $this->state['tasks'][$taskId];
        if ($parent['kind'] !== 'prepare' || empty($parent['parameters']['allowDynamicPlan'])) {
            throw new InvalidArgumentException('This attempt cannot publish child tasks.');
        }
        $tasks = $plan['tasks'] ?? null;
        if (!is_array($tasks) || !$tasks || count($tasks) > 1000 || array_is_list($tasks)
            || strlen(json_encode($plan, JSON_THROW_ON_ERROR)) > 786432) {
            throw new InvalidArgumentException('Invalid or oversized preparation plan.');
        }
        $fingerprint = hash('sha256', json_encode(self::canonical($plan), JSON_THROW_ON_ERROR));
        if (isset($parent['planFingerprint'])) {
            if (!hash_equals($parent['planFingerprint'], $fingerprint)) { throw new InvalidArgumentException('Frozen preparation plan changed.'); }
            return;
        }
        $candidate = []; $finalizers = []; $runId = $parent['runId'];
        foreach ($tasks as $name => $spec) {
            self::identifier((string) $name);
            $id = $taskId . ':' . $name;
            if (strlen($id) > 320 || isset($this->state['tasks'][$id]) || !is_array($spec)
                || !in_array($spec['kind'] ?? '', ['send', 'delete', 'finalize'], true)
                || !is_string($spec['dataset'] ?? null) || $spec['dataset'] === ''
                || !is_array($spec['parameters'] ?? []) || !is_array($spec['references'] ?? [])
                || !is_array($spec['dependencies'] ?? [])) {
                throw new InvalidArgumentException('Invalid child task specification.');
            }
            $dependencies = [$taskId];
            foreach ($spec['dependencies'] ?? [] as $dependency) {
                if (!is_string($dependency) || !isset($tasks[$dependency]) || $dependency === $name) {
                    throw new InvalidArgumentException('Invalid child task dependency.');
                }
                $dependencies[] = $taskId . ':' . $dependency;
            }
            $candidate[$id] = ['id' => $id, 'runId' => $runId, 'kind' => $spec['kind'],
                'parameters' => $spec['parameters'] ?? [], 'dataset' => $spec['dataset'],
                'dependencies' => array_values(array_unique($dependencies)), 'references' => $spec['references'] ?? [],
                'state' => 'queued', 'attemptCount' => 0, 'attempt' => null, 'retryAt' => null,
                'retryMonotonic' => null, 'blocked' => '', 'result' => null];
            if ($spec['kind'] === 'finalize') { $finalizers[] = $id; }
        }
        if (count($finalizers) !== 1) { throw new InvalidArgumentException('Preparation requires exactly one finalizer.'); }
        $finalizer = $finalizers[0];
        // Workers cannot omit an expected child from the completion boundary.
        $candidate[$finalizer]['dependencies'] = array_values(array_unique(array_merge(
            $candidate[$finalizer]['dependencies'], array_diff(array_keys($candidate), [$finalizer]))));
        $visiting = []; $visited = [];
        $visit = static function (string $id) use (&$visit, &$visiting, &$visited, $candidate, $taskId): void {
            if ($id === $taskId || isset($visited[$id])) { return; }
            if (isset($visiting[$id])) { throw new InvalidArgumentException('Cyclic preparation plan.'); }
            $visiting[$id] = true;
            foreach ($candidate[$id]['dependencies'] as $dependency) { $visit($dependency); }
            unset($visiting[$id]); $visited[$id] = true;
        };
        foreach (array_keys($candidate) as $id) { $visit($id); }
        // Validate the complete graph before mutating the accepted journal.
        foreach ($candidate as $id => $task) { $this->state['tasks'][$id] = $task; $this->state['runs'][$runId]['tasks'][] = $id; }
        $this->state['tasks'][$taskId]['planFingerprint'] = $fingerprint;
        $this->state['tasks'][$taskId]['planPublishedAt'] = $now;
    }
}
