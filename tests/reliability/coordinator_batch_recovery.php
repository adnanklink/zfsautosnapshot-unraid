<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
require $plugin . '/php/snapshot-manager-helpers.php';
require $plugin . '/php/coordinator-socket.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rpc($request) { $reply=zfsas_coordinator_request($request);check($reply['ok'],json_encode($reply));return $reply['result']; }
function until($predicate) { $until=microtime(true)+15;do { if($predicate())return;usleep(20000); }while(microtime(true)<$until);throw new RuntimeException('Timed out: '.@file_get_contents('/tmp/item-recovery/daemon.log')); }
$fixture='/tmp/item-recovery';mkdir($fixture,0775,true);
$config='/boot/config/plugins/zfs.autosnapshot';mkdir($config,0775,true);
file_put_contents($config.'/zfs_autosnapshot.conf',"DATASETS=\"tank/data:1G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"disabled\"\n");
file_put_contents($config.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\n");
file_put_contents($fixture.'/zfs', <<<'SH'
#!/bin/bash
if [[ "$1" == snapshot ]]; then
  echo "$2" >> /tmp/item-recovery/actions
  if [[ "$2" == tank/data@one ]]; then touch /tmp/item-recovery/started; sleep 60; fi
fi
SH);
chmod($fixture.'/zfs',0755);putenv('PATH='.$fixture.':'.getenv('PATH'));
function launch($plugin,$fixture) {
    $process=proc_open([PHP_BINARY,$plugin.'/php/coordinator-daemon.php'],[0=>['file','/dev/null','r'],1=>['file',$fixture.'/daemon.log','a'],2=>['file',$fixture.'/daemon.log','a']],$pipes);
    until(function(){try{return rpc(['action'=>'status'])!==null;}catch(Throwable $e){return false;}});return $process;
}
$process=null;
try {
    $process=launch($plugin,$fixture);
    $batch=zfsas_sm_new_batch('tank/data','take_snapshot');$batch['state']='queued';$batch['approvedAt']=time();
    foreach(['one','two'] as $name){$batch['items'][]=['snapshot'=>'tank/data@'.$name,'identity'=>'tank/data@'.$name.'#','guid'=>'','state'=>'queued','candidate'=>true];}
    zfsas_sm_batch_store($batch);
    $run=rpc(['action'=>'batch','token'=>$batch['token'],'dataset'=>'tank/data'])['runId'];
    until(fn()=>is_file($fixture.'/started'));
    // The ZFS side effect has happened; the coordinator and worker have no result.
    proc_terminate($process,9);proc_close($process);$process=null;
    $process=launch($plugin,$fixture);
    until(function()use($run){foreach(rpc(['action'=>'status'])['runs'] as $row){if($row['id']===$run)return $row['state']==='failed';}return false;});
    $visible=zfsas_sm_read_json_file(zfsas_sm_batch_path($batch['token']));
    check($visible['items'][0]['state']==='failed' && $visible['items'][0]['recoveryRequired'],'Interrupted item was not projected for review');
    check($visible['items'][1]['state']==='completed','Untouched item failed to progress');
    check(file($fixture.'/actions',FILE_IGNORE_NEW_LINES)===['tank/data@one','tank/data@two'],'Recovery repeated a mutation');
    $state=ZfsasCoordinatorState::readCommitted('/tmp/zfs-autosnapshot-coordinator');
    foreach($state['attempts'] as $attempt){check($attempt['state']==='stopped','Run settled before process shutdown');}
    echo "PASS: actual batch worker/coordinator crash, surviving mutation process shutdown, ambiguous item review, untouched item progress and authoritative projection\n";
}finally{if($process){proc_terminate($process,9);proc_close($process);}}
