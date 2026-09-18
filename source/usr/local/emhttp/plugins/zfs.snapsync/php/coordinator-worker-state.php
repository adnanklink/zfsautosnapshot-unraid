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
            || !in_array($type, ['progress', 'result', 'plan', 'plan_chunk', 'plan_seal', 'item_chunk', 'item_start', 'item_result', 'pressure_chunk', 'pressure_seal', 'pressure_authorize'], true)) {
            throw new InvalidArgumentException('Invalid worker publication.');
        }
        $fingerprint = hash('sha256', json_encode(self::canonical(['type' => $type, 'payload' => $payload]), JSON_THROW_ON_ERROR));
        $attempt =& $this->state['attempts'][$token];
        $last = $attempt['publication'] ?? ['sequence' => 0];
        if ($sequence === $last['sequence'] && ($last['fingerprint'] ?? '') === $fingerprint) {
            return $last['response'] ?? ['accepted' => true, 'sequence' => $sequence];
        }
        if ($sequence !== $last['sequence'] + 1 || isset($attempt['reportedResult'])) {
            throw new InvalidArgumentException('Out-of-order or conflicting worker publication.');
        }
        $response = ['accepted'=>true, 'sequence'=>$sequence];
        if (str_starts_with($type, 'pressure_')) {
            $response += $this->reportPressure($taskId, $type, $payload);
        } elseif (str_starts_with($type, 'item_')) {
            $response += $this->reportItem($taskId, $token, $type, $payload, $now);
        } elseif ($type === 'progress') {
            if (array_diff(array_keys($payload), ['phase', 'message', 'percent'])
                || !is_string($payload['phase'] ?? '') || strlen($payload['phase'] ?? '') > 80
                || !is_string($payload['message'] ?? '') || strlen($payload['message'] ?? '') > 4096
                || (isset($payload['percent']) && (!is_int($payload['percent']) || $payload['percent'] < 0 || $payload['percent'] > 100))) {
                throw new InvalidArgumentException('Invalid bounded progress report.');
            }
            $this->state['tasks'][$taskId]['progress'] = $payload;
        } elseif ($type === 'result') {
            self::checkedWorkerOutcome($payload);
            if (($payload['outcome'] ?? '') === 'success' && !empty($this->state['tasks'][$taskId]['parameters']['nativePlan']) && !isset($this->state['tasks'][$taskId]['planFingerprint'])) { throw new InvalidArgumentException('Native preparation cannot succeed without its expected child plan.'); }
            if (!empty($attempt['activeItem'])) { throw new InvalidArgumentException('Commit the active item outcome before finishing the attempt.'); }
            if (($payload['outcome'] ?? '') === 'success' && isset($this->state['plans'][$taskId])
                && empty($this->state['plans'][$taskId]['sealed'])) {
                throw new InvalidArgumentException('Preparation cannot succeed before its plan is sealed.');
            }
            // An acknowledged report is not completion. The executor must verify
            // the entire process group has stopped before consuming this result.
            if (($this->state['tasks'][$taskId]['parameters']['phase'] ?? '') === 'replication_snapshot' && $payload['outcome'] === 'success') {
                $reference=$payload['reference'] ?? null;$parameters=$this->state['tasks'][$taskId]['parameters'];
                if (!is_array($reference) || ($reference['snapshot'] ?? '') !== $parameters['source'].'@'.$parameters['snapshotName']
                    || ($reference['datasetGuid'] ?? '') !== $parameters['sourceDatasetGuid'] || ($reference['role'] ?? '') !== 'source'
                    || ($reference['dataset'] ?? '') !== $parameters['source'] || ($reference['endpoint'] ?? '') !== 'local') {
                    throw new InvalidArgumentException('Snapshot outcome lacks captured creation identity.');
                }
                self::checkedReferences([$reference]);$this->checkReferenceAdmission([$reference]);
                $this->state['tasks'][$taskId]['references']=[$reference];$this->registerReferences($taskId,[$reference]);
            }
            $attempt['reportedResult'] = $payload;
        } elseif ($type === 'plan_chunk') {
            $this->stageWorkerPlan($taskId, $payload);
        } elseif ($type === 'plan_seal') {
            $this->sealWorkerPlan($taskId, $payload, $now);
        } else {
            if (isset($this->state['plans'][$taskId])) { throw new InvalidArgumentException('A staged plan requires explicit sealing.'); }
            $this->publishWorkerPlan($taskId, $payload, $now);
        }
        $attempt['publication'] = ['sequence' => $sequence, 'fingerprint' => $fingerprint, 'response'=>$response];
        $this->commit();
        return $response;
    }

    private function stageWorkerPlan(string $taskId, array $payload): void
    {
        $parent = $this->state['tasks'][$taskId];
        $offset = $payload['offset'] ?? null; $digest = $payload['digest'] ?? null; $tasks = $payload['tasks'] ?? null;
        if ($parent['kind'] !== 'prepare' || empty($parent['parameters']['allowDynamicPlan'])
            || isset($parent['planFingerprint']) || !is_int($offset) || $offset < 0
            || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)
            || !is_array($tasks) || !$tasks || array_is_list($tasks) || count($tasks) > 50
            || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 786432) {
            throw new InvalidArgumentException('Invalid bounded plan chunk.');
        }
        foreach ($tasks as $name => $spec) {
            self::identifier((string) $name);
            if (!is_array($spec)) { throw new InvalidArgumentException('Invalid staged task.'); }
        }
        $header = $this->state['plans'][$taskId] ?? ['taskId'=>$taskId, 'digest'=>$digest, 'count'=>0, 'chunks'=>[], 'sealed'=>false];
        if ($header['digest'] !== $digest || $header['sealed']) { throw new InvalidArgumentException('Frozen plan identity changed.'); }
        $key = $taskId . ':chunk:' . $offset;
        $fingerprint = hash('sha256', json_encode(self::canonical($tasks), JSON_THROW_ON_ERROR));
        if (isset($this->state['plans'][$key])) {
            if ($this->state['plans'][$key]['fingerprint'] !== $fingerprint) { throw new InvalidArgumentException('Conflicting staged plan replay.'); }
            return;
        }
        if ($offset !== $header['count'] || $offset + count($tasks) > 50001) { throw new InvalidArgumentException('Plan chunk is out of order or exceeds the item limit.'); }
        $header['count'] += count($tasks); $header['chunks'][] = $key;
        $this->state['plans'][$key] = ['taskId'=>$taskId, 'fingerprint'=>$fingerprint, 'tasks'=>$tasks];
        $this->state['plans'][$taskId] = $header;
    }

    private function sealWorkerPlan(string $taskId, array $payload, int $now): void
    {
        $header = $this->state['plans'][$taskId] ?? null;
        if (!$header || ($payload['digest'] ?? null) !== $header['digest'] || ($payload['count'] ?? null) !== $header['count']) {
            throw new InvalidArgumentException('Plan seal does not match staged membership.');
        }
        if ($header['sealed']) { return; }
        $tasks = [];
        foreach ($header['chunks'] as $key) {
            foreach ($this->state['plans'][$key]['tasks'] as $name => $spec) {
                if (isset($tasks[$name])) { throw new InvalidArgumentException('Duplicate member across plan chunks.'); }
                $tasks[$name] = $spec;
            }
        }
        $plan = ['tasks'=>$tasks];
        if (!hash_equals($header['digest'], hash('sha256', json_encode(self::canonical($plan), JSON_THROW_ON_ERROR)))) {
            throw new InvalidArgumentException('Staged plan digest does not match its contents.');
        }
        $this->publishWorkerPlan($taskId, $plan, $now, true);
        foreach ($header['chunks'] as $key) { unset($this->state['plans'][$key]); }
        $header['sealed'] = true; $header['chunks'] = [];
        $this->state['plans'][$taskId] = $header;
    }

    private function publishWorkerPlan(string $taskId, array $plan, int $now, bool $staged = false): void
    {
        $parent = $this->state['tasks'][$taskId];
        if ($parent['kind'] !== 'prepare' || empty($parent['parameters']['allowDynamicPlan'])) {
            throw new InvalidArgumentException('This attempt cannot publish child tasks.');
        }
        $tasks = $plan['tasks'] ?? null;
        if (!is_array($tasks) || !$tasks || count($tasks) > ($staged ? 50001 : 1000) || array_is_list($tasks)
            || strlen(json_encode($plan, JSON_THROW_ON_ERROR)) > ($staged ? 128 * 1048576 : 786432)) {
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
                || !in_array($spec['kind'] ?? '', ['auto', 'prepare', 'send', 'delete', 'finalize'], true)
                || !is_string($spec['dataset'] ?? null) || $spec['dataset'] === ''
                || !is_array($spec['parameters'] ?? []) || !is_array($spec['references'] ?? [])
                || !is_array($spec['dependencies'] ?? [])) {
                throw new InvalidArgumentException('Invalid child task specification.');
            }
            self::checkedReferences($spec['references'] ?? []);
            $this->checkReferenceAdmission($spec['references'] ?? []);
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
        self::checkPlanReferenceConflicts($candidate);
        // Validate the complete graph before mutating the accepted journal.
        foreach ($candidate as $id => $task) { $this->state['tasks'][$id] = $task; $this->state['runs'][$runId]['tasks'][] = $id; $this->registerReferences($id, $task['references']); }
        // Consumers of dynamic preparation must also await its finalizer. A
        // planner exiting proves only that the graph is committed, not that its
        // transfers completed. Apply this within the same journal publication.
        foreach ($this->state['runs'][$runId]['tasks'] as $consumerId) {
            if (isset($candidate[$consumerId]) || $consumerId === $taskId) { continue; }
            if (in_array($taskId,$this->state['tasks'][$consumerId]['dependencies'],true)) {
                $this->state['tasks'][$consumerId]['dependencies'] = array_values(array_unique(array_merge(
                    $this->state['tasks'][$consumerId]['dependencies'],[$finalizer])));
            }
        }
        $this->state['tasks'][$taskId]['planFingerprint'] = $fingerprint;
        $this->state['tasks'][$taskId]['planPublishedAt'] = $now;
    }
}
