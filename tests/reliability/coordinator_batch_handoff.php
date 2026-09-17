<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/';
require $plugin . 'snapshot-manager-helpers.php';
require $plugin . 'coordinator-state.php';
require $plugin . 'coordinator-batch.php';
require_once $plugin . 'coordinator-socket.php';
if (($argv[1] ?? '') === '--server') {
    $root = $argv[2]; $journal = new ZfsasCoordinatorState($root . '/grant-journal');
    $receipt = $journal->submit('grant', ['tasks'=>['items'=>['kind'=>'batch']]], time());
    $task = $receipt['runId'] . ':items';
    $token = $journal->claim($task, hrtime(true)/1e9, time(), 'current-generation');
    $journal->started($task, $token, getmypid(), '123');
    $server = new ZfsasCoordinatorSocket('/var/run/zfs-autosnapshot-coordinator/control.sock',
        fn($request) => $journal->workerReport($request, 'current-generation', time()), fn($now) => $now + 30);
    file_put_contents($root . '/grant.json', json_encode(['task'=>$task, 'token'=>$token]));
    $server->serve(); exit;
}
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
check(str_contains(file_get_contents($root.'/worker.log'), 'No coordinator worker grant'), 'Worker failed for an unrelated reason');
check(file_get_contents(zfsas_sm_batch_path($batch['token'])) === $before, 'Ungrantable worker rewrote approval evidence');
check(!is_file(zfsas_sm_batches_dir() . '/' . hash('sha256', 'tank/data') . '.worker'), 'Worker acquired ownership before validating its grant');
$server = proc_open([PHP_BINARY, __FILE__, '--server', $root],
    [1=>['file',$root.'/server.log','a'], 2=>['file',$root.'/server.log','a']], $pipes);
try {
    for ($round = 0; $round < 100 && !is_file($root . '/grant.json'); $round++) { usleep(20000); }
    check(is_file($root . '/grant.json'), 'Grant server did not start');
    $grant = json_decode(file_get_contents($root . '/grant.json'), true);
    putenv('ZFSAS_TASK_ID=' . $grant['task']); putenv('ZFSAS_ATTEMPT_TOKEN=' . $grant['token']);
    putenv('ZFSAS_COORDINATOR_GENERATION=obsolete-generation');
    $worker = proc_open([PHP_BINARY, $plugin . 'snapshot-batch-worker.php', 'tank/data', $batch['token']],
        [1=>['file',$root.'/stale.log','a'], 2=>['file',$root.'/stale.log','a']], $pipes);
    check(proc_close($worker) === 1, 'Worker with stale generation started');
    check(str_contains(file_get_contents($root.'/stale.log'), 'Worker ownership expired'), 'Stale rejection did not come from coordinator');
    check(!is_file(zfsas_sm_batches_dir() . '/' . hash('sha256', 'tank/data') . '.worker'), 'Stale worker acquired resources');
    putenv('ZFSAS_COORDINATOR_GENERATION=current-generation');
    $worker = proc_open([PHP_BINARY, $plugin . 'snapshot-batch-worker.php', 'tank/data', $batch['token']],
        [1=>['file',$root.'/valid.log','a'], 2=>['file',$root.'/valid.log','a']], $pipes);
    check(proc_close($worker) === 0, 'Current grant could not inspect a completed batch: ' . file_get_contents($root . '/valid.log'));
} finally { proc_terminate($server, 9); proc_close($server); }
echo "PASS: deletion batch handoff requires fresh review, preserves terminal results and rejects ungranted worker launch\n";
