<?php
// Run with a read-only /boot fixture. Never create configuration here.
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync');
require $plugin . '/php/coordinator-socket.php';
require $plugin . '/php/snapshot-manager-helpers.php';
@mkdir('/usr/local/sbin', 0755, true);
file_put_contents('/usr/local/sbin/zfs_snapsync', "#!/bin/bash\nprintf 'run\\n' >> /tmp/flash-auto-runs\n");
chmod('/usr/local/sbin/zfs_snapsync', 0755);
$proc = proc_open([PHP_BINARY, $plugin . '/php/coordinator-daemon.php'], [0 => ['file','/dev/null','r'], 1 => ['file','/tmp/flash-coordinator.log','a'], 2 => ['file','/tmp/flash-coordinator.log','a']], $pipes);
function rpc($request) {
    $reply = zfsas_coordinator_request($request);
    if (!$reply['ok']) { throw new RuntimeException($reply['error']); }
    return $reply['result'];
}
try {
    for ($i=0;$i<100;$i++) {
        try { rpc(['action'=>'status']); break; } catch (RuntimeException $error) { usleep(20000); }
    }
    $receipt=rpc(['action'=>'auto','commandId'=>'flash-manual']);
    $done=false;
    for ($i=0;$i<100;$i++) {
        foreach (rpc(['action'=>'status'])['runs'] as $run) { if ($run['id']===$receipt['runId'] && $run['state']==='complete') { $done=true; break; } }
        if ($done) { break; } usleep(20000);
    }
    if (!$done) { throw new RuntimeException('Read-only flash execution did not complete'); }
    if (rpc(['action'=>'auto','commandId'=>'flash-manual']) !== $receipt) { throw new RuntimeException('Duplicate receipt changed'); }
    for ($i=0;$i<10;$i++) { rpc(['action'=>'status']); }
    // Approved batch authority is recorded in RAM without initializing flash.
    $batch=zfsas_sm_new_batch('tank/data','hold');
    $batch['state']='queued';$batch['approvedAt']=time();$batch['items']=[];
    zfsas_sm_batch_store($batch);
    rpc(['action'=>'batch','token'=>$batch['token'],'dataset'=>$batch['dataset']]);
    echo "PASS: coordinator launch, completion, duplicate submission, polling and batch admission with read-only boot flash\n";
} finally { proc_terminate($proc,9); proc_close($proc); }
