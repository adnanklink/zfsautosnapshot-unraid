<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/';
require $plugin . 'snapshot-manager-helpers.php';
require $plugin . 'coordinator-state.php';
require $plugin . 'coordinator-batch.php';
require $plugin . 'coordinator-deletion.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$root = '/tmp/deletion-items-' . bin2hex(random_bytes(6));
$journal = new ZfsasCoordinatorState($root);
$deletion = new ZfsasCoordinatorDeletion($journal, $root);
$batch = zfsas_sm_new_batch('tank/data', 'delete');
$batch['approvedAt'] = time(); $batch['state'] = 'queued'; $items = [];
for ($i = 0; $i < 51; $i++) {
    $items[] = ['snapshot'=>'tank/data@s' . $i, 'identity'=>'tank/data@s' . $i . '#' . ($i+1),
        'guid'=>(string) ($i+1), 'state'=>'queued', 'candidate'=>true];
}
zfsas_sm_batch_store(array_replace($batch, ['items'=>$items]));
$receipt = $journal->submit('batch-' . $batch['token'], ['manual'=>true, 'tasks'=>[
    'items'=>['kind'=>'batch', 'dataset'=>'tank/data', 'items'=>$items, 'parameters'=>['batch'=>$batch, 'token'=>$batch['token']]]]], time());
$parent = $receipt['runId'] . ':items';
$deletion->dispatchBatch($journal->state['tasks'][$parent]);
check(count($journal->state['runs']) === 51, 'Batch exceeded 50-item admission');
check(!in_array($parent, $journal->runnable(hrtime(true)/1e9), true), 'Dependency wait remained runnable');
check(count($journal->state['attempts']) === 0, 'Batch wait consumed a worker');
check(count($journal->state['tasks'][$parent]['dependencies']) === 50, 'Status omitted deletion dependencies');
foreach ($journal->state['tasks'][$parent]['items'] as $index => $id) {
    check($journal->state['items'][$id]['spec'] === $items[$index], 'Captured item specification changed');
}
// Legacy queue submission cannot create authority for a journal-owned batch.
$firstChild = current(array_filter($journal->state['tasks'], fn($task) => $task['kind'] === 'delete'));
$unapproved = $firstChild['parameters']['deleteJob'];
$unapproved['JOB_ID'] .= '-extra';
zfsas_ops_append_delete_queue_inbox(zfsas_ops_delete_queue_command_line($unapproved));
$deletion->tick(hrtime(true)/1e9);
check(count($journal->state['runs']) === 51 && is_file($root . '/deletion-review-required.log'), 'Inbox bypassed item authority');
// Model a crash after the last child receipt but before the final parent update.
$last = $journal->state['tasks'][$parent]['items'][49];
$journal->state['items'][$last]['state'] = 'queued';
unset($journal->state['items'][$last]['deletionTaskId'], $journal->state['items'][$last]['deleteJobId']);
$journal->state['tasks'][$parent]['dependencies'] = [];
$journal->state['tasks'][$parent]['state'] = 'queued'; $journal->state['tasks'][$parent]['blocked'] = '';
$journal->commit();
// Restart retains delegated membership and never creates a duplicate child.
unset($deletion, $journal);
$journal = new ZfsasCoordinatorState($root); $deletion = new ZfsasCoordinatorDeletion($journal, $root);
$deletion->dispatchBatch($journal->state['tasks'][$parent]);
check(count($journal->state['runs']) === 51, 'Restart recreated or exceeded bounded delegation');
$children = array_filter($journal->state['tasks'], fn($task) => $task['kind'] === 'delete');
foreach ($children as $index => $task) {
    $token = $journal->claim($task['id'], hrtime(true)/1e9, time());
    $journal->started($task['id'], $token, 123, '123');
    $failure = $task['parameters']['deleteJob']['SNAPSHOT'] === 'tank/data@s0';
    $journal->result($task['id'], $token, ['outcome'=>$failure ? 'validation_failure' : 'success',
        'itemState'=>'completed', 'message'=>$failure ? 'Injected failure' : 'Deleted'], hrtime(true)/1e9, time(), true);
    $deletion->changed($task['id']);
}
check(in_array($parent, $journal->runnable(hrtime(true)/1e9), true), 'Finished chunk did not wake parent');
check($journal->state['runs'][$receipt['runId']]['state'] !== 'failed', 'Partial failure canceled untouched items');
$deletion->dispatchBatch($journal->state['tasks'][$parent]);
check(count($journal->state['runs']) === 52, 'Second chunk lost or repeated items');
$id = $journal->state['tasks'][$parent]['items'][50];
$child = $journal->state['items'][$id]['deletionTaskId'];
$token = $journal->claim($child, hrtime(true)/1e9, time()); $journal->started($child, $token, 123, '123');
$journal->result($child, $token, ['outcome'=>'success', 'itemState'=>'completed'], hrtime(true)/1e9, time(), true);
$deletion->changed($child);
check($journal->state['runs'][$receipt['runId']]['state'] === 'failed', 'Failure missing from finalized batch');
$manifest = zfsas_sm_read_json_file(zfsas_sm_batch_path($batch['token']));
check(count(array_filter($manifest['items'], fn($item) => $item['state'] === 'completed')) === 50, 'Successful membership lost');
check($manifest['items'][0]['state'] === 'failed', 'Failed-only retry evidence missing');
// A contended compatibility projection retries without changing journal authority.
$path = zfsas_sm_batch_path($batch['token']);
$stale = $manifest; $stale['state'] = 'running'; zfsas_sm_batch_store($stale);
$lock = fopen($path . '.lock', 'c'); flock($lock, LOCK_EX);
$deletion->projectBatch($parent);
check(zfsas_sm_read_json_file($path)['state'] === 'running', 'Projection ignored endpoint lock');
flock($lock, LOCK_UN); fclose($lock);
$deletion->tick(hrtime(true)/1e9 + 31);
check(zfsas_sm_read_json_file($path)['state'] === 'complete', 'Deferred projection was lost');
unlink($path); unset($deletion, $journal);
$journal = new ZfsasCoordinatorState($root); $deletion = new ZfsasCoordinatorDeletion($journal, $root);
$deletion->tick(hrtime(true)/1e9);
check(zfsas_sm_read_json_file($path)['items'] === $manifest['items'], 'Restart did not reconstruct committed results');
echo "PASS: journal-owned deletion items, bounded delegation, no waiting worker, restart, immutable selection and partial outcomes\n";
