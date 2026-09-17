<?php
require_once __DIR__ . '/replication-inspection.php';

/** Build a frozen graph; publishing it atomically registers all snapshot references. */
function zfsas_replication_plan(array $request, array $inspection, string $revision, string $rateLimit = '0'): array
{
    ZfsasReplicationInspection::validate($request);
    if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) { throw new InvalidArgumentException('Captured configuration revision required.'); }
    if (!in_array($inspection['mode'] ?? '', ['full','incremental','resume','already_received'], true)) {
        throw new InvalidArgumentException('Full receive requires explicit admission of a new receiver; existing datasets will not be replaced.');
    }
    if (empty($request['createDestination'])) { $request['destinationGuid'] = $inspection['destinationDatasetGuid']; }
    if (!preg_match('/^(?:0|[1-9][0-9]*[bBkKmMgG]?)$/D',$rateLimit)) { throw new InvalidArgumentException('Invalid captured transfer rate.'); }
    $parameters = ['rateLimit'=>$rateLimit,'replication'=>$request, 'inspection'=>$inspection, 'revision'=>$revision];
    $task = static fn($kind,$phase,$dependencies) => ['kind'=>$kind,'dataset'=>$request['destination'],
        'parameters'=>$parameters+['phase'=>$phase], 'references'=>$inspection['references'], 'dependencies'=>$dependencies];
    if ($inspection['mode'] === 'already_received') {
        return ['tasks'=>['verify'=>$task('finalize','replication_verify',[])]];
    }
    return ['tasks'=>[
        'space'=>$task('prepare','replication_space',[]),
        'transfer'=>$task('send','replication_transfer',['space']),
        'verify'=>$task('finalize','replication_verify',['transfer'])]];
}

/** Recheck the immutable plan before space approval, transfer and finalization. */
function zfsas_replication_revalidate(array $parameters): array
{
    $result = ZfsasReplicationInspection::inspect($parameters['replication']);
    if ($result['outcome'] !== 'success') { return $result; }
    $current = $result['inspection']; $captured = $parameters['inspection'];
    if ($current['sourceDatasetGuid'] !== $captured['sourceDatasetGuid']
        || ($captured['destinationDatasetGuid'] !== null && $current['destinationDatasetGuid'] !== $captured['destinationDatasetGuid'])
        || (isset($parameters['expectedReceiverGuid']) && $current['destinationDatasetGuid'] !== $parameters['expectedReceiverGuid'])) {
        throw new InvalidArgumentException('Dataset identity changed after replication planning.');
    }
    if ($current['mode'] !== 'already_received' && ($current['mode'] !== $captured['mode'] || $current['base'] !== $captured['base'])) {
        throw new InvalidArgumentException('Incremental base changed after planning; review a new run.');
    }
    if ($current['mode'] === 'resume' && ($current['resumeHash'] ?? '') !== ($captured['resumeHash'] ?? '')) {
        throw new InvalidArgumentException('Resume token changed after approval; review Retry again.');
    }
    return $result;
}

function zfsas_replication_space(array $parameters): array
{
    $request = $parameters['replication']; $base = $parameters['inspection']['base']['snapshot'] ?? null;
    if ($parameters['inspection']['mode'] === 'resume') {
        $token=zfsas_replication_resume_token($parameters);
        $estimate=ZfsasReplicationInspection::command(['send','-nP','-t',$token]);
    } else { $estimate = ZfsasReplicationInspection::command(array_merge(['send','-nP'],$base === null ? [] : ['-i',$base],[$request['sourceSnapshot']])); }
    if (!preg_match('/^size\s+([0-9]+)$/m', $estimate, $match)
        || strlen($match[1]) > 18) { throw new RuntimeException('Unable to measure the incremental stream size.'); }
    $required = (int)$match[1];
    $capacity = $parameters['inspection']['mode'] === 'full' ? substr($request['destination'],0,strrpos($request['destination'],'/')) : $request['destination'];
    $available = trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','available','--',$capacity]));
    if (!ctype_digit($available) || strlen($available) > 18) { throw new RuntimeException('Incomplete receiver available-space measurement.'); }
    // Stream estimates are not guaranteed allocation sizes. Preserve a margin and
    // still treat any receive failure explicitly; never force rollback to fit.
    $required += max(16777216, (int)ceil($required / 20));
    if ((int)$available < $required) {
        return ['outcome'=>'validation_failure','reason'=>'space','message'=>"Insufficient destination space: need $required bytes including margin, measured $available available. This explicit send authorizes no snapshot cleanup; free space and Retry."];
    }
    return ['outcome'=>'success','requiredBytes'=>$required,'availableBytes'=>(int)$available];
}

function zfsas_replication_resume_token(array $parameters): string
{
    $token=trim(ZfsasReplicationInspection::command(['get','-H','-o','value','receive_resume_token','--',$parameters['replication']['destination']]));
    if ($token === '-' || !hash_equals($parameters['inspection']['resumeHash'] ?? '',hash('sha256',$token))) {
        throw new InvalidArgumentException('Resume token changed before execution.');
    }
    return $token;
}
