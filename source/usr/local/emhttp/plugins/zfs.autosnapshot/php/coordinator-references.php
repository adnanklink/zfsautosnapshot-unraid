<?php
/** Exact replication references, committed before dependent cleanup admission. */
trait ZfsasCoordinatorReferences
{
    private array $referenceNames = [];
    private array $referenceGuids = [];
    private array $indexedReferences = [];

    private function indexReference(string $id): void
    {
        $old = $this->indexedReferences[$id] ?? null;
        if ($old) {
            unset($this->referenceNames[$old['snapshot']][$id], $this->referenceGuids[$old['guid']][$id]);
            if (empty($this->referenceNames[$old['snapshot']])) { unset($this->referenceNames[$old['snapshot']]); }
            if (empty($this->referenceGuids[$old['guid']])) { unset($this->referenceGuids[$old['guid']]); }
        }
        unset($this->indexedReferences[$id]);
        $reference = $this->state['references'][$id] ?? null;
        if (!$reference) { return; }
        $this->indexedReferences[$id] = ['snapshot'=>$reference['snapshot'], 'guid'=>$reference['guid']];
        $this->referenceNames[$reference['snapshot']][$id] = true;
        $this->referenceGuids[$reference['guid']][$id] = true;
    }

    private static function checkedReferences(array $references): void
    {
        if (!array_is_list($references) || count($references) > 1000) { throw new InvalidArgumentException('Invalid reference list.'); }
        $seen = [];
        foreach ($references as $reference) {
            if (!is_array($reference) || array_diff(array_keys($reference), ['role','endpoint','dataset','datasetGuid','snapshot','guid'])
                || !in_array($reference['role'] ?? '', ['source','base','checkpoint','resume'], true)
                || !is_string($reference['endpoint'] ?? null) || !preg_match('/^[A-Za-z0-9_.:@-]{1,256}$/D', $reference['endpoint'])
                || !is_string($reference['dataset'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D', $reference['dataset'])
                || !is_string($reference['snapshot'] ?? null) || !str_starts_with($reference['snapshot'], $reference['dataset'] . '@')
                || !preg_match('/^[A-Za-z0-9_.:+-]+$/D', substr($reference['snapshot'], strlen($reference['dataset']) + 1))) {
                throw new InvalidArgumentException('Incomplete replication reference identity.');
            }
            foreach (['datasetGuid','guid'] as $field) {
                if (!is_string($reference[$field] ?? null) || !preg_match('/^[0-9]{1,20}$/D', $reference[$field])) {
                    throw new InvalidArgumentException('Reference GUIDs are required.');
                }
            }
            $key = json_encode(self::canonical($reference), JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) { throw new InvalidArgumentException('Duplicate replication reference.'); }
            $seen[$key] = true;
        }
    }

    private function checkReferenceAdmission(array $references): void
    {
        foreach ($this->activeTaskIds() as $id) {
            $job = $this->state['tasks'][$id]['parameters']['deleteJob'] ?? null;
            if (!$job) { continue; }
            foreach ($references as $reference) {
                // Snapshot GUIDs can be preserved by replication. Without a
                // verified receiver identity, conservatively exclude both names
                // and matching GUIDs rather than assuming hosts are distinct.
                if ($reference['snapshot'] === $job['SNAPSHOT'] || $reference['guid'] === $job['SNAPSHOT_GUID']) {
                    throw new InvalidArgumentException('Deletion already owns a required reference; prepare again after verified shutdown.');
                }
            }
        }
    }

    private static function checkPlanReferenceConflicts(array $tasks): void
    {
        $references = [];
        foreach ($tasks as $task) { $references = array_merge($references, $task['references'] ?? []); }
        foreach ($tasks as $task) {
            $job = $task['parameters']['deleteJob'] ?? null;
            if (!$job) { continue; }
            foreach ($references as $reference) {
                if ($reference['snapshot'] === ($job['SNAPSHOT'] ?? '') || $reference['guid'] === ($job['SNAPSHOT_GUID'] ?? '')) {
                    throw new InvalidArgumentException('Cleanup plan would delete its own required replication reference.');
                }
            }
        }
    }

    private function registerReferences(string $taskId, array $references): void
    {
        foreach ($references as $index => $reference) {
            $id = $taskId . ':reference:' . $index;
            $this->state['references'][$id] = $reference + ['id'=>$id, 'taskId'=>$taskId,
                'runId'=>$this->state['tasks'][$taskId]['runId']];
        }
    }

    public function deletionReferenceOwners(string $snapshot, string $guid): array
    {
        $owners = [];
        $matching = ($this->referenceNames[$snapshot] ?? []) + ($this->referenceGuids[$guid] ?? []);
        foreach ($matching as $id => $_) {
            $reference = $this->state['references'][$id];
            $run = $this->state['runs'][$reference['runId']] ?? null;
            if (!$run || (self::terminal($run['state']) && !$this->runRequiresReview($run['id']))) { continue; }
            $owners[$run['id']] = true;
        }
        return array_keys($owners);
    }
}
