<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-worker-client.php';
require_once __DIR__ . '/replication-plan.php';
try {
    // No metadata query, input read or other work before the live grant check.
    zfsas_coordinator_worker_report('progress',1,['phase'=>'destination_validation','message'=>'Inspecting replication identities and receiver metadata.']);
    $path=$argv[1] ?? ''; $task=getenv('ZFSAS_TASK_ID');
    if ($path !== '/tmp/zfs-snapsync-coordinator/attempt-inputs/' . hash('sha256',(string)$task) . '.inspection.json'
        || is_link($path)) { throw new InvalidArgumentException('Invalid captured inspection path.'); }
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (($input['taskId'] ?? '') !== $task) { throw new InvalidArgumentException('Inspection input belongs to another task.'); }
    try { $result=ZfsasReplicationInspection::inspect($input['request']); }
    catch (InvalidArgumentException $error) { $result=['outcome'=>'validation_failure','message'=>$error->getMessage()]; }
    catch (RuntimeException $error) { $result=['outcome'=>'transient_failure','message'=>$error->getMessage()]; }
    $sequence = 2;
    if (!empty($input['nativePlan']) && $result['outcome'] === 'success') {
        try {
            if (($result['inspection']['sourceDatasetGuid'] ?? null) !== ($input['sourceDatasetGuid'] ?? null)) { throw new InvalidArgumentException('Source dataset identity changed after submission.'); }
            $plan = zfsas_replication_plan($input['request'],$result['inspection'],$input['revision'],$input['rateLimit'] ?? '0');
            zfsas_coordinator_worker_report('plan',$sequence++,$plan);
        } catch (InvalidArgumentException $error) { $result=['outcome'=>'validation_failure','message'=>$error->getMessage()]; }
    }
    zfsas_coordinator_worker_report('result',$sequence,$result);
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage() . "\n"); exit(1); }
