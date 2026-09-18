<?php
if (!is_file('/.dockerenv')) { exit(77); }
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin.'/coordinator-socket.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"PREFIX=snapsync-auto-\nDATASETS=''\n");
$spec=json_encode(['abcdef123456'=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]]);
file_put_contents($config.'/zfs_send.conf',"SEND_JOBS='abcdef123456|tank/data|backup/data|6h|0G|0|local'\nSEND_SCHEDULE_SPECS='$spec'\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STOPPED"');
$runner=tempnam('/tmp','snapsync-now-');
file_put_contents($runner,'<?php $GLOBALS["csrf_token"]="fixture"; $_SERVER["REQUEST_METHOD"]="POST"; $_POST=["csrf_token"=>"fixture","command_id"=>$argv[2]]; require $argv[1];');
$daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/snapsync-now-daemon.log','a'],2=>['file','/tmp/snapsync-now-daemon.log','a']],$pipes);
try {
 for($i=0;$i<100;$i++){try{if(zfsas_coordinator_request(['action'=>'status'])['ok'])break;}catch(Throwable $e){}usleep(50000);}
 $invoke=static function($command)use($runner,$plugin){
  $proc=proc_open([PHP_BINARY,$runner,$plugin.'/run-send-now.php',$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
  check((bool)preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$output,$match),'Invalid endpoint response: '.$output.$errors);
  return json_decode($match[1],true);
 };
 $first=$invoke('manual-send-endpoint');check($first['ok'],json_encode($first));
 $second=$invoke('manual-send-endpoint');check($second['runs']===$first['runs'],'Retry duplicated operation');
 $other=$invoke('manual-send-overlap');check($other['ok'] && $other['runs']['abcdef123456']['runId']===$first['runs']['abcdef123456']['runId'],'Run Now overlapped active job');
 $state=ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');
 check(count($state['runs'])===1 && !$state['schedules'],'Run Now consumed cadence');
 echo "PASS: actual Run Now endpoint, stable receipts, overlap coalescing and preserved cadence\n";
} finally {proc_terminate($daemon,15);proc_close($daemon);unlink($runner);}
