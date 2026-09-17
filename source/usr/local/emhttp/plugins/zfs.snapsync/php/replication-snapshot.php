<?php
require_once __DIR__.'/replication-inspection.php';

/** Called only under a live mutation grant and the source dataset gate. */
function zfsas_replication_snapshot(array $intent, ?callable $execute=null): array
{
    $execute ??= [ZfsasReplicationInspection::class,'command'];
    foreach (['source','sourceDatasetGuid','snapshotName','scheduleId','occurrence'] as $field) {
        if (!is_string($intent[$field] ?? null)) { throw new InvalidArgumentException('Incomplete captured snapshot intent.'); }
    }
    $source=$intent['source'];$snapshot=$source.'@'.$intent['snapshotName'];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D',$source)
        || !preg_match('/^[A-Za-z0-9_.:+-]+$/D',$intent['snapshotName'])
        || !preg_match('/^[0-9]{1,20}$/D',$intent['sourceDatasetGuid'])
        || !preg_match('/^[a-f0-9]{12}$/D',$intent['scheduleId'])
        || !preg_match('/^[0-9]{1,12}$/D',$intent['occurrence'])) { throw new InvalidArgumentException('Invalid captured snapshot identity.'); }
    $guid=static function($name)use($execute){
        $value=trim($execute(['get','-H','-p','-o','value','guid','--',$name]));
        if (!preg_match('/^[0-9]{1,20}$/D',$value)) { throw new RuntimeException('Incomplete snapshot identity metadata.'); }
        return $value;
    };
    if ($guid($source)!==$intent['sourceDatasetGuid']) { throw new InvalidArgumentException('Source dataset changed after intent capture.'); }
    $inventory=$execute(['list','-H','-o','name','-t','snapshot','-d','1','--',$source]);
    if (strlen($inventory)>8*1048576) { throw new RuntimeException('Snapshot inventory exceeds the bounded limit.'); }
    $names=[];
    foreach (explode("\n",rtrim($inventory,"\n")) as $name) {
        if ($name==='') { continue; }
        if (!str_starts_with($name,$source.'@') || !preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($name,strlen($source)+1)) || isset($names[$name])) {
            throw new RuntimeException('Incomplete snapshot inventory.');
        }
        $names[$name]=true;
        if (count($names)>50000) { throw new RuntimeException('Snapshot inventory exceeds the bounded limit.'); }
    }
    $properties=['org.zfs.snapsync:schedule'=>$intent['scheduleId'],
        'org.zfs.snapsync:occurrence'=>$intent['occurrence'],'org.zfs.snapsync:source'=>$intent['sourceDatasetGuid']];
    if (!isset($names[$snapshot])) {
        // Recheck after inventory, immediately before a single non-recursive
        // creation. ZFS atomically creates the snapshot and its intent properties.
        if ($guid($source)!==$intent['sourceDatasetGuid']) { throw new InvalidArgumentException('Source dataset changed before snapshot creation.'); }
        $arguments=['snapshot'];foreach($properties as $key=>$value){$arguments[]='-o';$arguments[]=$key.'='.$value;}
        $execute(array_merge($arguments,['--',$snapshot]));
    }
    foreach ($properties as $key=>$value) {
        if (trim($execute(['get','-H','-o','value',$key,'--',$snapshot]))!==$value) {
            throw new InvalidArgumentException('Snapshot name has a different creation intent; refusing to adopt it.');
        }
    }
    $snapshotGuid=$guid($snapshot);
    if ($guid($source)!==$intent['sourceDatasetGuid'] || $guid($snapshot)!==$snapshotGuid) {
        throw new InvalidArgumentException('Snapshot identity changed while verifying creation.');
    }
    return ['outcome'=>'success','reference'=>['role'=>'source','endpoint'=>'local','dataset'=>$source,
        'datasetGuid'=>$intent['sourceDatasetGuid'],'snapshot'=>$snapshot,'guid'=>$snapshotGuid],
        'message'=>'Verified scheduled snapshot creation and intent metadata.'];
}
