<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-queue-helpers.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-scheduled-replication.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-send-scheduling.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$root='/tmp/snapsync-send-timing-'.bin2hex(random_bytes(6));
try {
 $j=new ZfsasCoordinatorState($root);$cache=[];$id='abcdef123456';
 $send=zfsas_send_defaults();$send['SEND_JOBS']="$id|tank/data|backup/data|6h|0G|0|local";
 $send['SEND_SCHEDULE_SPECS']=json_encode([$id=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>1000]]);
 $config=['send'=>$send,'auto'=>['PREFIX'=>'snapsync-auto-'],'timezone'=>new DateTimeZone('UTC'),'revision'=>str_repeat('a',64),'rawSend'=>'fixture'];
 $job=zfsas_send_parse_jobs($send['SEND_JOBS'])[0];
 check(zfsas_coordinator_send_tick($j,$config,1000,$cache)===22600 && !$j->state['runs'],'Ran on Save');
 $manual=zfsas_coordinator_submit_schedule($j,$job,$config,2000,true,'manual-one');
 check(zfsas_coordinator_submit_schedule($j,$job,$config,2001,true,'manual-one')===$manual,'Duplicate command created another run');
 zfsas_coordinator_send_tick($j,$config,22600,$cache);
 check(count($j->state['runs'])===1 && !isset($j->state['schedules'][$id]),'Scheduled run overlapped Run Now or consumed occurrence');
 $j->cancel($manual['runId'],22601);
 zfsas_coordinator_send_tick($j,$config,22601,$cache);
 check(count($j->state['runs'])===2 && $j->state['schedules'][$id]['accepted']===22600,'Due occurrence not admitted after Run Now');
 $run=$j->state['schedules'][$id]['runId'];$task=$run.':prepare';
 $j->rejectAdmission($task,['outcome'=>'validation_failure','message'=>'fixture'],0,22602);
 zfsas_coordinator_send_tick($j,$config,22603,$cache);check(count($j->state['runs'])===2,'Failed occurrence recreated');
 zfsas_coordinator_send_tick($j,$config,100000,$cache);
 check(count($j->state['runs'])===3 && $j->state['schedules'][$id]['accepted']===87400,'Missed occurrences were not coalesced');
 check($cache['jobs'][$id]['next']===109000,'Run Now shifted cadence');
 echo "PASS: native local first-run timing, stable manual receipts, shared overlap exclusion, accepted failures and coalesced catch-up\n";
} finally {unset($j);foreach(glob($root.'/*')?:[] as $path)unlink($path);@rmdir($root);}
