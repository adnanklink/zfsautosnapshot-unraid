<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/send-helpers.php';
require_once __DIR__.'/replication-pressure.php';
try {
    $task=getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if (!$task || $path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.job.pressure.json' || is_link($path)) {
        throw new InvalidArgumentException('Invalid pressure deletion capture.');
    }
    $capture=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (($capture['taskId'] ?? '')!==$task) { throw new InvalidArgumentException('Pressure capture belongs to another task.'); }
    $p=$capture['pressure'];$job=$capture['job'];$read=[ZfsasReplicationInspection::class,'command'];
    if (($p['policy']['mode'] ?? '')!=='older_anchors' || $p['revision']!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) {
        throw new InvalidArgumentException('Cleanup policy changed; no further anchor deletion is authorized.');
    }
    foreach ([[$job['DATASET'],$job['DATASET_GUID']],[$job['SNAPSHOT'],$job['SNAPSHOT_GUID']],
        [$job['PRESSURE_NEWEST_SNAPSHOT'],$job['PRESSURE_NEWEST_GUID']]] as [$name,$guid]) {
        if (trim($read(['get','-H','-p','-o','value','guid','--',$name]))!==$guid) { throw new InvalidArgumentException('A protected or selected snapshot identity changed; plan cleanup again.'); }
    }
    foreach ($p['inspection']['references'] as $reference) {
        if ($reference['snapshot']===$job['SNAPSHOT'] || $reference['guid']===$job['SNAPSHOT_GUID']) { throw new InvalidArgumentException('Anchor is a required replication reference.'); }
        if (trim($read(['get','-H','-p','-o','value','guid','--',$reference['snapshot']]))!==$reference['guid']) { throw new InvalidArgumentException('A required replication reference changed.'); }
    }
    $creation=trim($read(['get','-H','-p','-o','value','creation','--',$job['SNAPSHOT']]));
    if ($creation!==$job['SNAPSHOT_EPOCH'] || (int)$creation>=(int)$job['PRESSURE_CUTOFF']) { throw new InvalidArgumentException('Snapshot is inside the protected keep-all window.'); }
    $available=trim($read(['get','-H','-p','-o','value','available','--',$job['DATASET']]));
    if (!ctype_digit($available) || strlen($available)>18) { throw new InvalidArgumentException('Incomplete available-space measurement.'); }
    if ((int)$available >= $p['requiredBytes']) { echo 'Space target is already met; retained anchor preserved.'; exit(2); }
    zfsas_replication_pressure_capacity($job['DATASET'],$p['requiredBytes'],$read);
    $freeing=trim(ZfsasReplicationInspection::poolCommand(['get','-H','-p','-o','value','freeing',$job['DELETE_POOL']]));
    if (!ctype_digit($freeing)) { throw new InvalidArgumentException('Incomplete ZFS freeing measurement.'); }
    if (trim($freeing,'0')!=='') { echo 'Waiting for ZFS freeing before authorizing another anchor deletion.'; exit(4); }
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()); exit(1); }
