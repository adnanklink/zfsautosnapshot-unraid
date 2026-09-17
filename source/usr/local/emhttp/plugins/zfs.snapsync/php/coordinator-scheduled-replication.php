<?php
require_once __DIR__.'/replication-schedule-plan.php';

function zfsas_coordinator_schedule_command(array $task, ZfsasCoordinatorState $journal, string $root, string $revision): array
{
    $parameters=$task['parameters'];
    if($parameters['revision']!==$revision){return ['outcome'=>'validation_failure','reason'=>'configuration','message'=>'Configuration changed; the next scheduled occurrence will use the new settings.'];}
    if($parameters['phase']==='replication_run_verify'){
        foreach($task['dependencies'] as $id){
            $child=$journal->state['tasks'][$id];
            if($child['state']!=='complete' || ($child['result']['outcome']??'')!=='success'){
                return ['outcome'=>'validation_failure','message'=>'A required replication child lacks explicit success.'];
            }
        }
        $parameters['childrenVerified']=true;
    }
    if($parameters['phase']==='replication_member'){
        $parent=substr($task['id'],0,strrpos($task['id'],':'));
        $snapshot=$journal->state['tasks'][$parent.':'.$parameters['snapshotTask']] ?? null;
        $reference=$snapshot['result']['reference']??null;
        if(!$reference || $snapshot['state']!=='complete'){return ['outcome'=>'validation_failure','message'=>'Scheduled snapshot lacks verified identity evidence.'];}
        $parameters['sourceGuid']=$reference['guid'];
    }
    $directory=$root.'/attempt-inputs';if(!is_dir($directory)&&!mkdir($directory,0700,true)){throw new RuntimeException('Cannot create schedule capture storage.');}
    $path=$directory.'/'.hash('sha256',$task['id']).'.schedule.json';
    $text=json_encode(['taskId'=>$task['id'],'parameters'=>$parameters],JSON_THROW_ON_ERROR);
    if(@file_get_contents($path)!==$text && (file_put_contents($path.'.pending',$text)!==strlen($text)||!rename($path.'.pending',$path))){throw new RuntimeException('Cannot publish scheduled task capture.');}
    return ['/bin/bash',__DIR__.'/../scripts/coordinator-schedule-attempt.sh',$path,
        $parameters['source']??$parameters['job']['source'],$parameters['destination']??$parameters['job']['destination']];
}

function zfsas_coordinator_submit_schedule(ZfsasCoordinatorState $journal, array $job, array $config, int $occurrence, bool $manual=false, ?string $command=null): array
{
    if(($job['transport']??'')!=='local'||!preg_match('/^[a-f0-9]{12}$/D',$job['id']??'')||$occurrence<0){throw new InvalidArgumentException('Invalid native schedule admission.');}
    if(!str_contains($job['destination'],'/')){throw new InvalidArgumentException('Scheduled receiver requires an existing parent dataset.');}
    $prefix=$config['send']['SEND_SNAPSHOT_PREFIX'];
    $name=$prefix.$job['id'].'-'.$occurrence;
    ZfsasReplicationInspection::validate(['sourceSnapshot'=>$job['source'].'@'.$name,'sourceGuid'=>'0','destination'=>$job['destination']]);
    $command??='send-occurrence-'.$job['id'].'-'.$occurrence;
    return $journal->submit($command,['manual'=>$manual,'schedule'=>$manual?'':$job['id'],'occurrence'=>$occurrence,
        'revision'=>$config['revision'],'tasks'=>['prepare'=>['kind'=>'prepare','dataset'=>$job['source'],'parameters'=>[
            'phase'=>'replication_schedule','nativeSchedule'=>true,'nativePlan'=>true,'allowDynamicPlan'=>true,
            'job'=>$job,'revision'=>$config['revision'],'snapshotName'=>$name,'occurrence'=>$occurrence,
            'rateLimit'=>$config['send']['SEND_RATE_LIMIT']]]]],time());
}
