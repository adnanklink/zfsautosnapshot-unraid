<?php
require_once __DIR__ . '/replication-inspection.php';
require_once __DIR__ . '/coordinator-client.php';
require_once __DIR__ . '/send-helpers.php';

/** Explicit UI action: bounded metadata capture outside the coordinator loop. */
function zfsas_native_manual_send(string $snapshot, string $guid, string $destination, string $commandId): array
{
    $replication = ['sourceSnapshot'=>$snapshot,'sourceGuid'=>$guid,'destination'=>$destination];
    ZfsasReplicationInspection::validate($replication);
    if (!str_contains($destination,'/')) { throw new InvalidArgumentException('Choose a receiver below an existing parent dataset.'); }
    $parent = substr($destination,0,strrpos($destination,'/'));
    $readGuid = static function($name) {
        $value = trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$name]));
        if (!preg_match('/^[0-9]{1,20}$/D',$value)) { throw new RuntimeException('Incomplete dataset identity.'); }
        return $value;
    };
    $revision = zfsas_config_revision('/boot/config/plugins/zfs.snapsync');
    if ($commandId === '') { $commandId='manual-'.hash('sha256',$snapshot.'#'.$guid.'|'.$destination.'|'.$revision); }
    zfsas_coordinator_ensure();
    $existing = zfsas_coordinator_request(['action'=>'replication_receipt','commandId'=>$commandId]+$replication);
    if (!$existing['ok']) { throw new RuntimeException($existing['error'] ?? 'Command lookup failed.'); }
    if (!empty($existing['result']['found'])) { return $existing['result']['receipt']; }
    $names = explode("\n",trim(ZfsasReplicationInspection::command(['list','-H','-o','name','-r','-d','1','--',$parent])));
    if (!in_array($parent,$names,true)) { throw new RuntimeException('Receiver parent inventory is unavailable.'); }
    if (in_array($destination,$names,true)) { $replication['destinationGuid']=$readGuid($destination); }
    else { $replication += ['createDestination'=>true,'destinationParentGuid'=>$readGuid($parent)]; }
    $request = ['action'=>'replication','commandId'=>$commandId,'replication'=>$replication,'revision'=>$revision,
        'sourceDatasetGuid'=>$readGuid(explode('@',$snapshot)[0])];
    zfsas_coordinator_ensure();
    $response = zfsas_coordinator_request($request);
    if (!$response['ok']) { throw new RuntimeException($response['error'] ?? 'Coordinator rejected replication.'); }
    return $response['result'];
}
