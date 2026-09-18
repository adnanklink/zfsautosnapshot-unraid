<?php
require_once __DIR__.'/replication-cleanup.php';

/** Reject known capacity limits that destroying receiver snapshots cannot fix. */
function zfsas_replication_pressure_capacity(string $destination, int $required, ?callable $read=null): void
{
    $read??=[ZfsasReplicationInspection::class,'command'];
    $values=explode("\n",trim($read(['get','-H','-p','-o','value','refquota,referenced','--',$destination])));
    if (count($values)!==2 || !ctype_digit($values[0]) || !ctype_digit($values[1]) || strlen($values[0])>18 || strlen($values[1])>18) {
        throw new InvalidArgumentException('Incomplete reference-quota metadata; no anchors may be deleted.');
    }
    if ((int)$values[0]>0 && (int)$values[0]-(int)$values[1]<$required) {
        throw new InvalidArgumentException('Destination reference quota cannot satisfy the space target through snapshot deletion. Review the quota or free-space target.');
    }
    for ($dataset=$destination; $dataset!==''; $dataset=str_contains($dataset,'/') ? substr($dataset,0,strrpos($dataset,'/')) : '') {
        $quota=trim($read(['get','-H','-p','-o','value','quota','--',$dataset]));
        if (!ctype_digit($quota) || strlen($quota)>18) { throw new InvalidArgumentException('Incomplete quota metadata; no anchors may be deleted.'); }
        if ((int)$quota>0 && (int)$quota<$required) { throw new InvalidArgumentException('A destination quota is smaller than the required space target. No anchor deletion can meet it.'); }
    }
}

function zfsas_replication_pressure_proposal(array $parameters, array $shortage, int &$sequence): array
{
    if (($parameters['cleanupPolicy']['mode'] ?? '')!=='older_anchors' || $parameters['inspection']['mode']==='full') { return $shortage; }
    zfsas_replication_pressure_capacity($parameters['replication']['destination'],$shortage['requiredBytes']);
    $candidate=$parameters['pressureCandidate'] ?? null;
    if (empty($parameters['pressurePlanned'])) {
        $tasks=zfsas_replication_anchor_candidates($parameters['replication'],$parameters['inspection'],$parameters['cleanupPolicy']);
        $jobs=array_values(array_map(static fn($task)=>$task['parameters']['deleteJob'],$tasks));
        $candidate=$jobs[0] ?? null;$offset=0;
        foreach(array_chunk($jobs,50) as $chunk) {
            zfsas_coordinator_worker_report('pressure_chunk',$sequence++,['offset'=>$offset,'candidates'=>$chunk]);$offset+=count($chunk);
        }
        zfsas_coordinator_worker_report('pressure_seal',$sequence++,['count'=>$offset]);
    }
    if ($candidate===null) {
        $shortage['message']='Insufficient destination space after eligible cleanup. The keep-all window, newest checkpoint and protected references remain preserved. Free space or review the target before another run.';
        return $shortage;
    }
    return ['outcome'=>'wait','reason'=>'dependency','delay'=>1,'pressureDelete'=>$candidate['SNAPSHOT_GUID'],
        'requiredBytes'=>$shortage['requiredBytes'],'availableBytes'=>$shortage['availableBytes'],
        'message'=>'Proposing the oldest eligible retained anchor for measured low-space cleanup.'];
}
