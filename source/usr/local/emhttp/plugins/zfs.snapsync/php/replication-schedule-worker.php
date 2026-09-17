<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/coordinator-worker-client.php';
require_once __DIR__.'/replication-schedule-plan.php';
require_once __DIR__.'/replication-snapshot.php';
require_once __DIR__.'/send-helpers.php';
try{
    zfsas_coordinator_worker_report('progress',2,['phase'=>'scheduled_preparation','message'=>'Checking captured scheduled replication work.']);$sequence=3;
    $path=$argv[1]??'';$task=getenv('ZFSAS_TASK_ID');
    if($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',(string)$task).'.schedule.json'||is_link($path)){throw new InvalidArgumentException('Invalid scheduled task capture.');}
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(($input['taskId']??'')!==$task){throw new InvalidArgumentException('Capture belongs to another task.');}
    $p=$input['parameters'];
    try{
        if($p['revision']!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync')){throw new InvalidArgumentException('Configuration changed; prepare a new run.');}
        if($p['phase']==='replication_run_verify' && !empty($p['childrenVerified'])){
            $result=['outcome'=>'success','message'=>'Every captured replication member completed verification.'];
        }elseif($p['phase']==='replication_schedule'){
            $plan=zfsas_replication_schedule_plan($p);zfsas_replication_publish_plan($plan,$sequence);
            $result=['outcome'=>'success','message'=>'Captured fixed dataset membership and scheduled child tasks.'];
        }elseif($p['phase']==='replication_snapshot'){
            $result=zfsas_replication_snapshot($p);
        }elseif($p['phase']==='replication_member'){
            $request=['sourceSnapshot'=>$p['source'].'@'.$p['snapshotName'],'sourceGuid'=>$p['sourceGuid'],'destination'=>$p['destination']];
            $parent=substr($p['destination'],0,strrpos($p['destination'],'/'));
            $names=explode("\n",trim(ZfsasReplicationInspection::command(['list','-H','-o','name','-r','-d','1','--',$parent])));
            if(!in_array($parent,$names,true)){throw new RuntimeException('Receiver parent inventory is unavailable.');}
            $identity=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',in_array($p['destination'],$names,true)?$p['destination']:$parent]));
            if(in_array($p['destination'],$names,true)){$request['destinationGuid']=$identity;}
            else{$request+=['createDestination'=>true,'destinationParentGuid'=>$identity];}
            $result=ZfsasReplicationInspection::inspect($request);
            if($result['outcome']==='success'){
                if($result['inspection']['sourceDatasetGuid']!==$p['sourceDatasetGuid']){throw new InvalidArgumentException('Captured member dataset changed.');}
                $plan=zfsas_replication_plan($request,$result['inspection'],$p['revision'],$p['rateLimit']);
                zfsas_replication_publish_plan($plan,$sequence);
            }
        }else{throw new InvalidArgumentException('Unknown scheduled replication phase.');}
    }catch(InvalidArgumentException $error){$result=['outcome'=>'validation_failure','message'=>$error->getMessage()];}
     catch(RuntimeException $error){$result=['outcome'=>'transient_failure','message'=>$error->getMessage()];}
    zfsas_coordinator_worker_report('result',$sequence,$result);
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
