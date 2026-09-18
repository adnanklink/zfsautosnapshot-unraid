<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-replication.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejects($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid publication accepted');}
$roots=[];
function fixture(){
 global $roots;
 $root='/tmp/snapsync-pressure-fault-'.bin2hex(random_bytes(5));$roots[]=$root;
 $j=new ZfsasCoordinatorState($root);$revision=str_repeat('c',64);
 $policy=['mode'=>'older_anchors','scheduleId'=>'abcdef123456','prefix'=>'snapsync-send-','sendConfigHash'=>str_repeat('b',64),'keepAll'=>14];
 $p=['phase'=>'replication_space','cleanupPolicy'=>$policy,'revision'=>$revision,
 'replication'=>['sourceSnapshot'=>'tank/data@next','sourceGuid'=>'99','destination'=>'backup/data'], 'inspection'=>['destinationDatasetGuid'=>'20']];
 $run=$j->submit('fixture',['tasks'=>['space'=>['kind'=>'prepare','parameters'=>$p],'transfer'=>['kind'=>'send','dependencies'=>['space']]]],100)['runId'];
 return [$j,$root,$run.':space',$revision];
}
function start($j,$gate,$time){$g=str_repeat('a',48);$t=$j->claim($gate,$time,(int)$time,$g);check($j->started($gate,$t,123,'1234'),'Start failed');return ['taskId'=>$gate,'token'=>$t,'generation'=>$g];}
function report($j,$b,$seq,$type,$payload){return $j->workerReport($b+['sequence'=>$seq,'type'=>$type,'payload'=>$payload],$b['generation'],100);}
function candidate($i){return ['DATASET'=>'backup/data','DATASET_GUID'=>'20','SNAPSHOT'=>'backup/data@snapsync-send-abcdef123456-'.$i,'SNAPSHOT_NAME'=>'snapsync-send-abcdef123456-'.$i,'SNAPSHOT_GUID'=>(string)(30+$i),'SEND_CONFIG_HASH'=>str_repeat('b',64),'SEND_SCHEDULE_JOB_ID'=>'abcdef123456','CLEANUP_REASON'=>'low_space_anchor','DELETE_SCOPE'=>'destination_checkpoint'];}
function finish($j,$b,$r,$time){check($j->result($b['taskId'],$b['token'],$r,$time,(int)$time,true),'Result rejected');}
try {
 // Interrupted append cannot publish the partial chunk; a recovered unsealed
 // manifest must be discarded before another preparation worker is launched.
 [$j,$root,$gate,$revision]=fixture();$b=start($j,$gate,0);
 report($j,$b,1,'pressure_chunk',['offset'=>0,'candidates'=>[candidate(0)]]);
 finish($j,$b,['outcome'=>'transient_failure'],1);
 unset($j);file_put_contents($root.'/journal.ndjson','{"unfinished":',FILE_APPEND);
 $j=new ZfsasCoordinatorState($root);check($j->pressureCandidate($gate)===null,'Partial manifest granted deletion');
 $command=zfsas_coordinator_replication_command($j->state['tasks'][$gate],$root,$revision,$j);
 check(array_is_list($command) && !isset($j->state['plans'][$gate.':pressure']),'Recovery mixed old and new inventories');
 check(count($j->state['tasks'])===2,'Interrupted manifest created deletion task');
 unset($j);
 // More than one chunk, out-of-order delivery, conflicting replay, restart,
 // configuration invalidation, and stale worker generation.
 [$j,$root,$gate,$revision]=fixture();$b=start($j,$gate,0);
 $first=array_map('candidate',range(0,49));
 rejects(fn()=>report($j,$b,1,'pressure_chunk',['offset'=>50,'candidates'=>[candidate(50)]]));
 report($j,$b,1,'pressure_chunk',['offset'=>0,'candidates'=>$first]);
 rejects(fn()=>report($j,$b,2,'pressure_chunk',['offset'=>0,'candidates'=>[candidate(99)]]));
 report($j,$b,2,'pressure_chunk',['offset'=>50,'candidates'=>[candidate(50)]]);
 rejects(fn()=>report($j,$b,3,'pressure_seal',['count'=>50]));
 report($j,$b,3,'pressure_seal',['count'=>51]);
 rejects(fn()=>report($j,array_replace($b,['generation'=>str_repeat('f',48)]),4,'pressure_seal',['count'=>51]));
 finish($j,$b,['outcome'=>'wait','reason'=>'space','delay'=>1,'availableBytes'=>10],1);
 unset($j);$j=new ZfsasCoordinatorState($root);
 check($j->pressureCandidate($gate)['SNAPSHOT_GUID']==='30','Sealed manifest lost after restart');
 $result=zfsas_coordinator_replication_command($j->state['tasks'][$gate],$root,str_repeat('d',64),$j);
 check($result['outcome']==='validation_failure','Changed configuration retained mutation authority');
 // Execute exactly one child; measuring sufficient space must preserve all others.
 $b=start($j,$gate,2);$r=['outcome'=>'wait','reason'=>'dependency','pressureDelete'=>'30','requiredBytes'=>100,'availableBytes'=>10];
 finish($j,$b,$r,3);$child=$j->state['tasks'][$gate]['pressureChild'];
 rejects(fn()=>report($j,$b,1,'pressure_seal',['count'=>51]));
 $c=start($j,$child,4);finish($j,$c,['outcome'=>'success','itemState'=>'completed'],5);
 $b=start($j,$gate,6);finish($j,$b,['outcome'=>'success','requiredBytes'=>100,'availableBytes'=>101],7);
 check(count($j->state['tasks'])===3 && $j->state['plans'][$gate.':pressure']['cursor']===1,'Sufficient space authorized extra deletions');
 check(in_array(substr($gate,0,-5).'transfer',$j->runnable(8),true),'Transfer did not unblock');
 unset($j);
 // Cross a full chunk boundary using real result transitions. Restart after
 // the first child result; neither its successful item nor the cursor may replay.
 [$j,$root,$gate,$revision]=fixture();$b=start($j,$gate,0);
 report($j,$b,1,'pressure_chunk',['offset'=>0,'candidates'=>array_map('candidate',range(0,49))]);
 report($j,$b,2,'pressure_chunk',['offset'=>50,'candidates'=>[candidate(50)]]);
 report($j,$b,3,'pressure_seal',['count'=>51]);
 for($i=0;$i<51;$i++) {
  if($i>0)$b=start($j,$gate,$i*4);
  check($j->pressureCandidate($gate)['SNAPSHOT_GUID']===(string)(30+$i),'Chunk traversal skipped or repeated a candidate');
  finish($j,$b,array_replace($r,['pressureDelete'=>(string)(30+$i)]),$i*4+1);
  $child=$j->state['tasks'][$gate]['pressureChild'];$c=start($j,$child,$i*4+2);
  finish($j,$c,['outcome'=>'success','itemState'=>$i%2?'skipped':'completed'],$i*4+3);
  if($i===0){unset($j);$j=new ZfsasCoordinatorState($root);}
 }
 check($j->pressureCandidate($gate)===null && count($j->state['tasks'])===53,'Exhaustion did not preserve fixed manifest membership');
 $b=start($j,$gate,205);finish($j,$b,['outcome'=>'validation_failure','reason'=>'space','message'=>'No eligible anchors remain.'],206);
 check(!in_array(substr($gate,0,-5).'transfer',$j->runnable(207),true),'Exhausted cleanup admitted transfer');
 unset($j);
 // A canceled space gate cannot grant the next anchor after its first deletion.
 [$j,$root,$gate,$revision]=fixture();$b=start($j,$gate,0);
 report($j,$b,1,'pressure_chunk',['offset'=>0,'candidates'=>[candidate(0),candidate(1)]]);
 report($j,$b,2,'pressure_seal',['count'=>2]);finish($j,$b,$r,1);
 $child=$j->state['tasks'][$gate]['pressureChild'];$c=start($j,$child,2);finish($j,$c,['outcome'=>'success','itemState'=>'completed'],3);
 $j->cancel($j->state['tasks'][$gate]['runId'],4);
 check(!$j->runnable(10) && count($j->state['tasks'])===3,'Cancellation admitted next deletion');
 unset($j);
 // Monotonic no-progress deadline: waits do not exhaust retries; real progress
 // resets the deadline and wall clock changes do not influence it.
 [$j,$root,$gate,$revision]=fixture();
 foreach([[0,10],[299,10],[300,11],[599,11],[600,11]] as [$time,$available]) {
  $b=start($j,$gate,$time);finish($j,$b,['outcome'=>'wait','reason'=>'space','delay'=>1,'requiredBytes'=>100,'availableBytes'=>$available],$time);
  if($time<600)check($j->state['tasks'][$gate]['state']==='waiting' && $j->state['tasks'][$gate]['attemptCount']===0,'Space wait consumed retries or timed out early');
 }
 check($j->state['tasks'][$gate]['state']==='failed' && str_contains($j->state['tasks'][$gate]['result']['message'],'five minutes'),'Stalled freeing failed to stop');
 echo "PASS: interrupted publication, unsealed/sealed restart, chunk fences, stale attempts, configuration changes, stop-after-space, cancellation between deletions and monotonic freeing deadline\n";
} finally {
 unset($j);
 foreach($roots as $root){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);}
}
