<?php
require_once __DIR__ . '/replication-inspection.php';

function zfsas_coordinator_replication_inspection_command(array $task, string $root): array
{
    $request = $task['parameters']['replication'] ?? null;
    try {
        if (!is_array($request)) { throw new InvalidArgumentException('Missing captured replication request.'); }
        ZfsasReplicationInspection::validate($request);
    } catch (InvalidArgumentException $error) { return ['outcome'=>'validation_failure','message'=>$error->getMessage()]; }
    $directory = $root . '/attempt-inputs';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Cannot create inspection input storage.'); }
    $path = $directory . '/' . hash('sha256',$task['id']) . '.inspection.json';
    $text = json_encode(['taskId'=>$task['id'],'request'=>$request,'rateLimit'=>$task['parameters']['rateLimit'] ?? '0','nativePlan'=>!empty($task['parameters']['nativePlan']),'revision'=>$task['parameters']['revision'] ?? '', 'sourceDatasetGuid'=>$task['parameters']['sourceDatasetGuid'] ?? null],JSON_THROW_ON_ERROR);
    if (@file_get_contents($path) !== $text) {
        if (file_put_contents($path . '.pending',$text) !== strlen($text) || !rename($path . '.pending',$path)) {
            throw new RuntimeException('Cannot capture replication inspection.');
        }
    }
    return [PHP_BINARY,__DIR__ . '/replication-inspection-worker.php',$path];
}
