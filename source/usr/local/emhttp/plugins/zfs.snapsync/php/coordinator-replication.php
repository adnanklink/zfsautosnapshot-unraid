<?php
require_once __DIR__ . '/replication-plan.php';

function zfsas_coordinator_replication_command(array $task, string $root, string $revision, ?ZfsasCoordinatorState $journal = null): array
{
    $parameters = $task['parameters'];
    if (($parameters['revision'] ?? '') !== $revision) {
        return ['outcome'=>'validation_failure','reason'=>'configuration','message'=>'Configuration changed; review a new replication run.'];
    }
    try { ZfsasReplicationInspection::validate($parameters['replication'] ?? []); }
    catch (InvalidArgumentException $error) { return ['outcome'=>'validation_failure','message'=>$error->getMessage()]; }
    if ($task['kind'] === 'finalize' && $journal !== null) {
        foreach ($task['dependencies'] as $id) {
            $child = $journal->state['tasks'][$id];
            if ($child['kind'] === 'send') {
                $guid = $child['result']['inspection']['destinationDatasetGuid'] ?? null;
                if ($child['state'] !== 'complete' || !is_string($guid)) { return ['outcome'=>'validation_failure','message'=>'Expected child lacks receiver identity evidence.']; }
                $parameters['expectedReceiverGuid'] = $guid;
            }
        }
    }
    $directory = $root . '/attempt-inputs';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Cannot create replication capture directory.'); }
    $path = $directory . '/' . hash('sha256',$task['id']) . '.replication.json';
    $text = json_encode(['taskId'=>$task['id'],'parameters'=>$parameters],JSON_THROW_ON_ERROR);
    if (@file_get_contents($path) !== $text && (file_put_contents($path.'.pending',$text) !== strlen($text) || !rename($path.'.pending',$path))) {
        throw new RuntimeException('Cannot publish captured replication parameters.');
    }
    return ['/bin/bash',__DIR__.'/../scripts/coordinator-replication-attempt.sh',$path,
        explode('@',$parameters['replication']['sourceSnapshot'])[0],$parameters['replication']['destination']];
}

function zfsas_coordinator_submit_replication(ZfsasCoordinatorState $journal, array $request, string $revision, array $sendConfig = []): array
{
    $replication = $request['replication'] ?? [];
    if (!is_array($replication)) { throw new InvalidArgumentException('Captured replication request required.'); }
    ZfsasReplicationInspection::validate($replication);
    $sourceDatasetGuid = $request['sourceDatasetGuid'] ?? '';
    if (!is_string($sourceDatasetGuid) || !preg_match('/^[0-9]{1,20}$/D',$sourceDatasetGuid)
        || !is_string($request['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$request['revision'])) {
        throw new InvalidArgumentException('Captured dataset GUID and configuration revision required.');
    }
    $rateLimit = (string)($sendConfig['SEND_RATE_LIMIT'] ?? '0');
    $prior = $journal->state['commands'][$request['commandId'] ?? '']['runId'] ?? null;
    if ($prior !== null) { $rateLimit = $journal->state['commands'][$request['commandId']]['context']['rateLimit'] ?? '0'; }
    $source = explode('@',$replication['sourceSnapshot'])[0];
    $spec = ['receiptData'=>['replicationSelection'=>zfsas_replication_selection_digest($replication),'rateLimit'=>$rateLimit],'manual'=>true,'revision'=>$request['revision'],'tasks'=>['prepare'=>[
        'kind'=>'prepare','dataset'=>$source,'parameters'=>['phase'=>'replication_inspect','nativePlan'=>true,
            'allowDynamicPlan'=>true,'retryOf'=>$request['retryOf'] ?? '', 'rateLimit'=>$rateLimit,'revision'=>$request['revision'],'replication'=>$replication,'sourceDatasetGuid'=>$sourceDatasetGuid],
        'references'=>[['role'=>'source','endpoint'=>'local','dataset'=>$source,'datasetGuid'=>$sourceDatasetGuid,
            'snapshot'=>$replication['sourceSnapshot'],'guid'=>$replication['sourceGuid']]]]]];
    $command = $request['commandId'] ?? '';
    if (!is_string($command) || $command === '') { throw new InvalidArgumentException('Stable command ID required.'); }
    if (!isset($journal->state['commands'][$command]) && $request['revision'] !== $revision) {
        throw new InvalidArgumentException('Configuration changed; review replication again.');
    }
    return $journal->submit($command,$spec,time());
}

function zfsas_coordinator_replication_receipt(ZfsasCoordinatorState $journal, array $request): array
{
    $command = $request['commandId'] ?? '';
    if (!is_string($command)) { throw new InvalidArgumentException('Invalid command ID.'); }
    $receipt = $journal->state['commands'][$command] ?? null;
    if ($receipt === null) { return ['found'=>false]; }
    if (!hash_equals($receipt['context']['replicationSelection'] ?? '',zfsas_replication_selection_digest($request))) {
        throw new InvalidArgumentException('Command ID already has a different selection.');
    }
    return ['found'=>true,'receipt'=>$receipt];
}

function zfsas_replication_selection_digest(array $request): string
{
    return hash('sha256',json_encode([$request['sourceSnapshot'] ?? null,$request['sourceGuid'] ?? null,$request['destination'] ?? null],JSON_THROW_ON_ERROR));
}

function zfsas_coordinator_retry_replication(ZfsasCoordinatorState $journal, string $runId, string $revision, array $sendConfig): array
{
    $run=$journal->state['runs'][$runId] ?? null;
    $parameters=$journal->state['tasks'][$runId.':prepare']['parameters'] ?? [];
    if (!$run || !$run['manual'] || !in_array($run['state'],['failed','canceled'],true) || empty($parameters['nativePlan'])) {
        throw new InvalidArgumentException('Retry requires a stopped native manual replication run. Review a new Send after reboot or history expiry.');
    }
    if ($run['revision'] !== $revision) { throw new InvalidArgumentException('Configuration changed; review and submit a new Send.'); }
    $request=['commandId'=>'retry-'.$runId,'revision'=>$revision,'sourceDatasetGuid'=>$parameters['sourceDatasetGuid'],
        'replication'=>$parameters['replication'],'retryOf'=>$runId];
    $request['replication']['allowResume']=true;
    return zfsas_coordinator_submit_replication($journal,$request,$revision,$sendConfig);
}
