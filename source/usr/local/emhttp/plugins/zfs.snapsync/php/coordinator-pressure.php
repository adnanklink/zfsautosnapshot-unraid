<?php
/** Frozen RAM candidates and one outstanding child per space gate. */
trait ZfsasCoordinatorPressure
{
    public function pressureCandidate(string $taskId): ?array
    {
        $header=$this->state['plans'][$taskId.':pressure'] ?? null;
        if (!$header || !$header['sealed'] || $header['cursor'] >= $header['count']) { return null; }
        $offset=intdiv($header['cursor'],50)*50;
        return $this->state['plans'][$taskId.':pressure:'.$offset]['candidates'][$header['cursor']-$offset];
    }

    private function reportPressure(string $taskId, string $type, array $payload): array
    {
        $task=$this->state['tasks'][$taskId]; $p=$task['parameters'];
        if ($type==='pressure_authorize') {
            if (empty($p['pressure']) || $payload) { throw new InvalidArgumentException('Invalid pressure deletion grant.'); }
            $job=$p['deleteJob'];
            if ($this->deletionReferenceOwners($job['SNAPSHOT'],$job['SNAPSHOT_GUID'])) {
                throw new InvalidArgumentException('Snapshot acquired a protected replication reference.');
            }
            return ['authorized'=>true];
        }
        if (($p['phase'] ?? '')!=='replication_space' || ($p['cleanupPolicy']['mode'] ?? '')!=='older_anchors') {
            throw new InvalidArgumentException('Task has no anchor cleanup authority.');
        }
        $key=$taskId.':pressure';
        $header=$this->state['plans'][$key] ?? ['taskId'=>$taskId,'sealed'=>false,'count'=>0,'cursor'=>0];
        if ($type==='pressure_seal') {
            if (($payload['count'] ?? null)!==$header['count']) { throw new InvalidArgumentException('Incomplete pressure manifest.'); }
            $header['sealed']=true; $this->state['plans'][$key]=$header;
            return ['sealed'=>true];
        }
        $offset=$payload['offset'] ?? null; $candidates=$payload['candidates'] ?? null;
        if (!is_int($offset) || $offset % 50 !== 0 || !is_array($candidates) || !array_is_list($candidates) || !$candidates || count($candidates)>50
            || strlen(json_encode($payload,JSON_THROW_ON_ERROR))>262144) { throw new InvalidArgumentException('Invalid bounded pressure manifest chunk.'); }
        $chunkKey=$key.':'.$offset;
        if (isset($this->state['plans'][$chunkKey])) {
            if ($this->state['plans'][$chunkKey]['candidates']!==$candidates) { throw new InvalidArgumentException('Pressure manifest changed.'); }
            return ['accepted'=>true];
        }
        if ($header['sealed'] || $offset!==$header['count'] || $offset+count($candidates)>50000) { throw new InvalidArgumentException('Pressure manifest is sealed or out of order.'); }
        $destination=$p['replication']['destination']; $policy=$p['cleanupPolicy'];
        $prefix=$destination.'@'.$policy['prefix'].$policy['scheduleId'].'-';
        foreach ($candidates as $job) {
            if (!is_array($job) || ($job['DATASET'] ?? '')!==$destination
                || ($job['DATASET_GUID'] ?? '')!==$p['inspection']['destinationDatasetGuid']
                || !str_starts_with($job['SNAPSHOT'] ?? '',$prefix)
                || ($job['SNAPSHOT'] ?? '')!==$destination.'@'.($job['SNAPSHOT_NAME'] ?? '')
                || !preg_match('/^[0-9]{1,20}$/D',$job['SNAPSHOT_GUID'] ?? '')
                || ($job['SEND_CONFIG_HASH'] ?? '')!==$policy['sendConfigHash']
                || ($job['SEND_SCHEDULE_JOB_ID'] ?? '')!==$policy['scheduleId']
                || ($job['CLEANUP_REASON'] ?? '')!=='low_space_anchor'
                || ($job['DELETE_SCOPE'] ?? '')!=='destination_checkpoint') {
                throw new InvalidArgumentException('Pressure candidate exceeds captured authority.');
            }
            foreach ($job as $field=>$value) {
                if (!preg_match('/^[A-Z_]+$/D',$field) || !is_string($value) || strpbrk($value,"\r\n\0")!==false) {
                    throw new InvalidArgumentException('Invalid pressure candidate field.');
                }
            }
        }
        $this->state['plans'][$chunkKey]=['taskId'=>$taskId,'candidates'=>$candidates];
        $header['count']+=count($candidates);$this->state['plans'][$key]=$header;
        return ['accepted'=>true];
    }

    /** Called only after verified worker shutdown, before committing its result. */
    private function pressureOutcome(string $taskId, array $result, float $monotonic): array
    {
        $task=&$this->state['tasks'][$taskId];
        if (($task['parameters']['phase'] ?? '')!=='replication_space' && empty($task['parameters']['pressure'])) { return $result; }
        if (($result['outcome'] ?? '')==='wait' && ($result['reason'] ?? '')==='space') {
            $available=$result['availableBytes'] ?? (isset($task['parameters']['pressure']) ? 0 : null);
            if (is_int($available)) {
                $progress=$task['spaceProgress'] ?? null;
                if (!$progress || $available>$progress['available'] || $monotonic<$progress['since']) {
                    $task['spaceProgress']=['available'=>$available,'since'=>$monotonic];
                } elseif ($monotonic-$progress['since']>=300) {
                    return ['outcome'=>'validation_failure','reason'=>'space','requiredBytes'=>$result['requiredBytes'] ?? null,
                        'availableBytes'=>$available,'message'=>'ZFS freeing has made no measured space progress for five minutes. Free space or review the destination limits before another run.'];
                }
            }
        }
        if (empty($result['pressureDelete'])) { return $result; }
        $candidate=$this->pressureCandidate($taskId);
        if (($result['outcome'] ?? '')!=='wait' || !$candidate || $result['pressureDelete']!==$candidate['SNAPSHOT_GUID']
            || !is_int($result['requiredBytes'] ?? null) || $result['requiredBytes']<0) {
            throw new InvalidArgumentException('Invalid pressure deletion proposal.');
        }
        $header=&$this->state['plans'][$taskId.':pressure']; $index=$header['cursor']++;
        // Other runs' references are exclusions, not destructive retry attempts.
        if ($this->deletionReferenceOwners($candidate['SNAPSHOT'],$candidate['SNAPSHOT_GUID'])) {
            return ['outcome'=>'wait','reason'=>'space','delay'=>1,'message'=>'Skipped an anchor protected by another replication run.'];
        }
        $id=$task['runId'].':pressure-'.substr(hash('sha256',$taskId.':'.$index),0,32);
        $parameters=['deleteJob'=>$candidate,'nativeSchedule'=>true,'pressure'=>[
            'gateId'=>$taskId,'requiredBytes'=>$result['requiredBytes'],
            'revision'=>$task['parameters']['revision'],'policy'=>$task['parameters']['cleanupPolicy'],
            'inspection'=>$task['parameters']['inspection'],'replication'=>$task['parameters']['replication']]];
        $this->state['tasks'][$id]=['id'=>$id,'runId'=>$task['runId'],'kind'=>'delete','dataset'=>$candidate['DATASET'],
            'parameters'=>$parameters,'dependencies'=>[],'references'=>[],'state'=>'queued','attemptCount'=>0,
            'attempt'=>null,'retryAt'=>null,'retryMonotonic'=>null,'blocked'=>'','result'=>null];
        $this->state['runs'][$task['runId']]['tasks'][]=$id;
        if (isset($task['pressureChild'])) { $task['dependencies']=array_values(array_diff($task['dependencies'],[$task['pressureChild']])); }
        $task['pressureChild']=$id;$task['dependencies'][]=$id;
        return ['outcome'=>'wait','reason'=>'dependency','delay'=>1,'requiredBytes'=>$result['requiredBytes'],
            'availableBytes'=>$result['availableBytes'],'message'=>'Waiting for one authorized retention anchor deletion before measuring space again.'];
    }
}
