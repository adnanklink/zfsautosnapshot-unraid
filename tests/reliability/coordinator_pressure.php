<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-pressure.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe pressure publication accepted');}
$root='/tmp/snapsync-pressure-test-'.bin2hex(random_bytes(5));
try {
 $j=new ZfsasCoordinatorState($root);$generation=str_repeat('a',48);
 $policy=['mode'=>'older_anchors','scheduleId'=>'abcdef123456','prefix'=>'snapsync-send-','sendConfigHash'=>str_repeat('b',64),'keepAll'=>14];
 $p=['phase'=>'replication_space','cleanupPolicy'=>$policy,'revision'=>str_repeat('c',64),'replication'=>['destination'=>'backup/data'],
     'inspection'=>['destinationDatasetGuid'=>'20']];
 $run=$j->submit('pressure-fixture',['tasks'=>['space'=>['kind'=>'prepare','parameters'=>$p], 'transfer'=>['kind'=>'send','dependencies'=>['space']]]],100)['runId'];
 $gate=$run.':space';$token=$j->claim($gate,0,100,$generation);$j->started($gate,$token,123,'1234');
 $base=['taskId'=>$gate,'token'=>$token,'generation'=>$generation];
 $job=['DATASET'=>'backup/data','DATASET_GUID'=>'20','SNAPSHOT'=>'backup/data@snapsync-send-abcdef123456-old',
     'SNAPSHOT_NAME'=>'snapsync-send-abcdef123456-old','SNAPSHOT_GUID'=>'30','SEND_CONFIG_HASH'=>$policy['sendConfigHash'],
     'SEND_SCHEDULE_JOB_ID'=>$policy['scheduleId'],'CLEANUP_REASON'=>'low_space_anchor','DELETE_SCOPE'=>'destination_checkpoint'];
 $chunk=$base+['sequence'=>1,'type'=>'pressure_chunk','payload'=>['offset'=>0,'candidates'=>[$job]]];
 $bad=$chunk;$bad['payload']['candidates'][0]['DATASET']='backup/unrelated';reject(fn()=>$j->workerReport($bad,$generation,101));
 $j->workerReport($chunk,$generation,101);$seq=$j->state['sequence'];$j->workerReport($chunk,$generation,101);
 check($seq===$j->state['sequence'],'Duplicate chunk changed journal');check($j->pressureCandidate($gate)===null,'Unsealed manifest granted candidates');
 $j->workerReport($base+['sequence'=>2,'type'=>'pressure_seal','payload'=>['count'=>1]],$generation,101);
 $result=['outcome'=>'wait','reason'=>'dependency','pressureDelete'=>'30','requiredBytes'=>100,'availableBytes'=>10];
 $j->workerReport($base+['sequence'=>3,'type'=>'result','payload'=>$result],$generation,101);
 check(count($j->state['tasks'])===2,'Deletion started before verified preparation shutdown');
 check(!$j->result($gate,$token,$result,1,101,false),'Unverified shutdown admitted deletion');
 $j->result($gate,$token,$result,1,101,true);
 check(count($j->state['tasks'])===3,'One deletion not admitted');$child=$j->state['tasks'][$gate]['pressureChild'];
 check($j->runnable(5)===[$child],'Waiting transfer consumed admission');
 check($j->state['tasks'][$gate]['attemptCount']===0,'Cleanup dependency consumed retry');
 $j->checkpoint();unset($j);$j=new ZfsasCoordinatorState($root);
 check($j->state['plans'][$gate.':pressure']['cursor']===1,'Recovery lost manifest cursor');
 $token=$j->claim($child,5,105,$generation);$j->started($child,$token,124,'1235');
 $grant=['taskId'=>$child,'token'=>$token,'generation'=>$generation,'sequence'=>1,'type'=>'pressure_authorize','payload'=>[]];
 check($j->workerReport($grant,$generation,105)['authorized'],'Current deletion grant rejected');
 $j->cancel($run,106);reject(fn()=>$j->workerReport($grant,$generation,106));
 $j->stopped($token,107,7);check($j->state['runs'][$run]['state']==='canceled','Pressure cancellation did not finish');
 $read=static fn($args)=>$args[5]==='refquota,referenced'?"100\n90":"0";
 reject(fn()=>zfsas_replication_pressure_capacity('backup/data',20,$read));
 zfsas_replication_pressure_capacity('backup/data',20,static fn($args)=>$args[5]==='refquota,referenced'?"0\n90":"0");
 echo "PASS: bounded pressure authority, duplicate publication, sealed membership, verified shutdown, single deletion, recovery, cancellation and impossible quota rejection\n";
} finally {unset($j);foreach(glob($root.'/*')?:[] as $path)unlink($path);@rmdir($root);}
