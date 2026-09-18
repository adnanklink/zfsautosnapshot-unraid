<?php
require_once __DIR__.'/replication-inspection.php';
require_once __DIR__.'/snapshot-cleanup.php';

/** Read-only destination retention plan; estimates never constitute space approval. */
function zfsas_replication_cleanup(array $request, array $inspection, array $policy, ?callable $read=null, ?int $now=null): array
{
    return zfsas_replication_cleanup_candidates($request, $inspection, $policy, $read, $now, false);
}

/** A proposal only: callers must obtain a fresh coordinator deletion grant. */
function zfsas_replication_anchor_candidates(array $request, array $inspection, array $policy, ?callable $read=null, ?int $now=null): array
{
    if (($policy['mode'] ?? 'retention_only') !== 'older_anchors') { return []; }
    return zfsas_replication_cleanup_candidates($request, $inspection, $policy, $read, $now, true);
}

function zfsas_replication_cleanup_candidates(array $request, array $inspection, array $policy, ?callable $read, ?int $now, bool $anchors): array
{
    if (($inspection['mode'] ?? '')==='full') { return []; }
    ZfsasReplicationInspection::validate($request);
    foreach(['keepAll','keepDaily','keepWeekly'] as $field){
        if(!is_int($policy[$field]??null)||$policy[$field]<0||$policy[$field]>365000){throw new InvalidArgumentException('Invalid captured retention policy.');}
    }
    if($policy['keepAll']>$policy['keepDaily']||$policy['keepDaily']>$policy['keepWeekly']
        ||!preg_match('/^[a-f0-9]{12}$/D',$policy['scheduleId']??'')
        ||!preg_match('/^[A-Za-z0-9_.:+-]+$/D',$policy['prefix']??'')
        ||!preg_match('/^[a-f0-9]{64}$/D',$policy['sendConfigHash']??'')){throw new InvalidArgumentException('Invalid cleanup authority.');}
    $read??=[ZfsasReplicationInspection::class,'command'];$now??=time();$destination=$request['destination'];
    if(trim($read(['get','-H','-p','-o','value','guid','--',$destination]))!==$inspection['destinationDatasetGuid']){
        throw new InvalidArgumentException('Receiver identity changed before cleanup planning.');
    }
    $text=$read(['list','-H','-p','-t','snapshot','-o','name,guid,createtxg,creation,used,userrefs,clones','-d','1','--',$destination]);
    if(strlen($text)>8*1048576){throw new RuntimeException('Cleanup inventory exceeds the bounded limit.');}
    $rows=[];$prefix=$destination.'@'.$policy['prefix'].$policy['scheduleId'].'-';
    foreach(explode("\n",rtrim($text,"\n")) as $line){
        if($line===''){continue;}$f=explode("\t",$line);
        if(count($f)!==7||!str_starts_with($f[0],$destination.'@')||!preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($f[0],strlen($destination)+1))||isset($rows[$f[0]])){throw new RuntimeException('Incomplete cleanup inventory.');}
        foreach([1,2,3,4,5] as $index){if(!preg_match('/^[0-9]{1,20}$/D',$f[$index])){throw new RuntimeException('Incomplete cleanup numeric metadata.');}}
        if(strlen($f[3])>12){throw new RuntimeException('Invalid snapshot creation time.');}
        $rows[$f[0]]=['snapshot'=>$f[0],'guid'=>$f[1],'txg'=>$f[2],'created'=>(int)$f[3],'used'=>$f[4],'holds'=>$f[5],'clones'=>$f[6]];
        if(count($rows)>50000){throw new RuntimeException('Cleanup inventory exceeds 50,000 snapshots.');}
    }
    $compare=static function($a,$b){$a=ltrim($a,'0');$b=ltrim($b,'0');return strlen($a)<=>strlen($b)?:strcmp($a,$b);};
    uasort($rows,static fn($a,$b)=>$compare($b['txg'],$a['txg']));
    $protectedNames=[];$protectedGuids=[];
    foreach($inspection['references'] as $reference){$protectedNames[$reference['snapshot']]=true;$protectedGuids[$reference['guid']]=true;}
    $newest=true;$newestRow=null;$days=[];$weeks=[];$tasks=[];
    foreach($rows as $row){
        if(!str_starts_with($row['snapshot'],$prefix)){continue;}
        if($newest){$newest=false;$newestRow=$row;continue;}
        $age=$now-$row['created'];$eligible=false;
        if($age>$policy['keepWeekly']*86400){$eligible=true;}
        elseif($age>$policy['keepDaily']*86400){$key=zfsas_sm_retention_week($row['created']);$eligible=isset($weeks[$key]);$weeks[$key]=true;}
        elseif($age>$policy['keepAll']*86400){$key=date('Y-m-d',$row['created']);$eligible=isset($days[$key]);$days[$key]=true;}
        if ($anchors) { $eligible = !$eligible && $age > $policy['keepAll']*86400; }
        if(!$eligible||$row['holds']!=='0'||$row['clones']!=='-'||isset($protectedNames[$row['snapshot']])||isset($protectedGuids[$row['guid']])){continue;}
        $id=($anchors ? 'native-anchor-' : 'native-retention-').substr(hash('sha256',$row['snapshot'].'#'.$row['guid'].'|'.$policy['sendConfigHash']),0,40);
        $job=['JOB_ID'=>$id,'REQUESTED_EPOCH'=>(string)$now,'QUEUE_SORT'=>(string)$now,'DATASET'=>$destination,
            'DATASET_GUID'=>$inspection['destinationDatasetGuid'],'SNAPSHOT'=>$row['snapshot'],'SNAPSHOT_NAME'=>explode('@',$row['snapshot'])[1],
            'SNAPSHOT_EPOCH'=>(string)$row['created'],'SNAPSHOT_GUID'=>$row['guid'],'SNAPSHOT_CREATETXG'=>$row['txg'],
            'CLEANUP_REASON'=>$anchors ? 'low_space_anchor' : 'retention',
            'DELETE_POOL'=>explode('/',$destination)[0],'ESTIMATED_RECLAIM_BYTES'=>$row['used'],'SEND_PROTECTED'=>'1',
            'DELETE_SCOPE'=>'destination_checkpoint','SEND_SCHEDULE_JOB_ID'=>$policy['scheduleId'],'SEND_CONFIG_HASH'=>$policy['sendConfigHash']];
        if ($anchors) {
            $job['PRESSURE_NEWEST_SNAPSHOT']=$newestRow['snapshot'];
            $job['PRESSURE_NEWEST_GUID']=$newestRow['guid'];
            $job['PRESSURE_CUTOFF']=(string)($now-$policy['keepAll']*86400);
        }
        $tasks['cleanup-'.count($tasks)]=['kind'=>'delete','dataset'=>$destination,'parameters'=>['deleteJob'=>$job,'nativeSchedule'=>true],'dependencies'=>[]];
    }
    if ($anchors) {
        uasort($tasks, static function ($a, $b) use ($compare) {
            $a=$a['parameters']['deleteJob']; $b=$b['parameters']['deleteJob'];
            return $compare($a['SNAPSHOT_EPOCH'],$b['SNAPSHOT_EPOCH'])
                ?: $compare($a['SNAPSHOT_CREATETXG'],$b['SNAPSHOT_CREATETXG'])
                ?: strcmp($a['SNAPSHOT'],$b['SNAPSHOT']);
        });
    }
    return $tasks;
}
