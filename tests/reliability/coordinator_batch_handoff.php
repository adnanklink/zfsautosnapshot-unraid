<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/';
require $plugin . 'snapshot-manager-helpers.php';
require $plugin . 'coordinator-state.php';
require $plugin . 'coordinator-batch.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$root = '/tmp/batch-handoff-' . bin2hex(random_bytes(6));
$journal = new ZfsasCoordinatorState($root);
$batch = zfsas_sm_new_batch('tank/data', 'delete');
$batch['state'] = 'running'; $batch['approvedAt'] = time();
foreach (['queued', 'running', 'deleting', 'completed', 'skipped', 'failed'] as $index => $state) {
    $batch['items'][] = ['identity'=>'tank/data@s' . $index . '#' . $index,
        'snapshot'=>'tank/data@s' . $index, 'guid'=>(string) $index, 'state'=>$state];
}
$provenId = 'proven-' . $batch['token'];
$batch['items'][] = ['identity'=>'tank/data@proven#123', 'snapshot'=>'tank/data@proven', 'guid'=>'123',
    'state'=>'deleting', 'deleteJobId'=>$provenId];
zfsas_sm_ensure_dir(zfsas_ops_status_dir() . '/delete-results');
file_put_contents(zfsas_ops_status_dir() . '/delete-results/' . $provenId . '.result', "completed\tDeleted exact snapshot.\n");
zfsas_sm_batch_store($batch);
$receipt = $journal->submit('legacy-batch', ['manual'=>true, 'tasks'=>[
    'items'=>['kind'=>'batch', 'parameters'=>['token'=>$batch['token']]]]], time());
$id = $receipt['runId'] . ':items';
$journal->rejectAdmission($id, ['outcome'=>'validation_failure', 'recoveryRequired'=>true], hrtime(true)/1e9, time());
zfsas_coordinator_project_batch($journal, $id);
$projected = zfsas_sm_read_json_file(zfsas_sm_batch_path($batch['token']));
foreach ($projected['items'] as $index => $item) {
    if ($index < 3) {
        check($item['state'] === 'failed' && $item['recoveryRequired'], 'Pending deletion escaped fresh review');
    } elseif ($index === 6) { check($item['state'] === 'completed' && empty($item['recoveryRequired']), 'Committed deletion result lost during handoff'); }
    else { check($item === $batch['items'][$index], 'Proven result changed during handoff'); }
}
check(zfsas_sm_batch_payload($projected)['recoveryRequired'], 'Handoff omitted recovery status');
// A bare compatibility flag is not a coordinator grant. Reject before creating
// worker ownership files, inspecting ZFS or publishing another manifest.
$before = file_get_contents(zfsas_sm_batch_path($batch['token']));
putenv('ZFSAS_COORDINATED=1');
foreach (['ZFSAS_TASK_ID', 'ZFSAS_ATTEMPT_TOKEN', 'ZFSAS_COORDINATOR_GENERATION'] as $name) { putenv($name); }
$worker = proc_open([PHP_BINARY, $plugin . 'snapshot-batch-worker.php', 'tank/data', $batch['token']],
    [1=>['file',$root.'/worker.log','a'], 2=>['file',$root.'/worker.log','a']], $pipes);
check(proc_close($worker) === 1, 'Worker accepted an ungranted compatibility launch');
check(str_contains(file_get_contents($root.'/worker.log'), 'Legacy batch execution is disabled'), 'Worker failed for an unrelated reason');
check(file_get_contents(zfsas_sm_batch_path($batch['token'])) === $before, 'Ungrantable worker rewrote approval evidence');
check(!is_file(zfsas_sm_batches_dir() . '/' . hash('sha256', 'tank/data') . '.worker'), 'Worker acquired ownership before validating its grant');
foreach (['obsolete-generation', 'current-generation'] as $generation) {
    putenv('ZFSAS_TASK_ID=run-example:items'); putenv('ZFSAS_ATTEMPT_TOKEN=' . str_repeat('a', 48));
    putenv('ZFSAS_COORDINATOR_GENERATION=' . $generation);
    $worker = proc_open([PHP_BINARY, $plugin . 'snapshot-batch-worker.php', 'tank/data', $batch['token']],
        [1=>['file',$root.'/disabled.log','a'], 2=>['file',$root.'/disabled.log','a']], $pipes);
    check(proc_close($worker) === 1, 'Legacy environment restored disabled worker authority');
    check(file_get_contents(zfsas_sm_batch_path($batch['token'])) === $before, 'Disabled worker changed approval evidence');
}
echo "PASS: deletion batch handoff requires fresh review, preserves terminal results and rejects ungranted worker launch\n";
