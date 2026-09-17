<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-state.php';
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-retention.php';
$root = '/tmp/coordinator-retention-' . bin2hex(random_bytes(6));
$state = new ZfsasCoordinatorState($root);
$now = time(); $old = $now - 31 * 86400;
$revision = str_repeat('a', 64); $orphan = str_repeat('b', 64);
$receipt = $state->submit('active', ['tasks' => ['batch' => ['kind' => 'batch', 'parameters' => ['token' => str_repeat('a', 32), 'revision' => $revision]]]], $old);
$token = $state->claim($state->state['runs'][$receipt['runId']]['tasks'][0], 1, $old);
mkdir($root . '/attempts/' . $token, 0700, true);
mkdir($root . '/attempts/' . str_repeat('b', 48), 0700, true);
mkdir($root . '/config/' . $revision, 0700, true);
mkdir($root . '/config/' . $orphan, 0700, true);
$batches = $root . '/batches'; mkdir($batches);
foreach (['a' => 'running', 'b' => 'complete', 'c' => 'review', 'd' => 'queued'] as $id => $status) {
    file_put_contents($batches . '/' . str_repeat($id, 32) . '.json', json_encode(['state' => $status, 'created' => $old]));
}
$delete = $state->submit('delete', ['tasks' => ['snapshot' => ['kind' => 'delete', 'parameters' => ['deleteJob' => ['JOB_ID' => 'active-delete']]]]], $old);
mkdir($root . '/delete-results'); mkdir($root . '/attempt-inputs');
$input = hash('sha256', $delete['runId'] . ':snapshot');
foreach ([$input, str_repeat('f', 64)] as $id) { file_put_contents($root . '/attempt-inputs/' . $id . '.job', 'captured'); file_put_contents($root . '/attempt-inputs/' . $id . '.job.approval.json', '{}'); }
foreach (['active-delete', 'orphan-delete'] as $id) {
    file_put_contents($root . '/delete-results/' . $id . '.result', 'completed');
    touch($root . '/delete-results/' . $id . '.result', $old);
}
$child = $state->submit('finished-child', ['tasks' => ['snapshot' => ['kind' => 'delete', 'parameters' => ['ownerRunId' => $receipt['runId']]]]], $old);
$childId = $child['runId'] . ':snapshot';
$childAttempt = $state->claim($childId, 1, $old);
$state->started($childId, $childAttempt, 123, '123');
$state->result($childId, $childAttempt, ['outcome' => 'success'], 1, $old, true);
$state->prune($now);
if (!isset($state->state['tasks'][$childId])) { throw new RuntimeException('Pruning lost an unfinished owner’s completed child'); }
zfsas_coordinator_prune_artifacts($state, $root, $batches, $now, $root . '/delete-results');
if (!is_file($root . '/delete-results/active-delete.result') || is_file($root . '/delete-results/orphan-delete.result')
    || !is_file($root . '/attempt-inputs/' . $input . '.job') || is_file($root . '/attempt-inputs/' . str_repeat('f', 64) . '.job')) {
    throw new RuntimeException('Deletion retention lost active evidence or retained expired artifacts');
}
if (!is_file($root . '/attempt-inputs/' . $input . '.job.approval.json')
    || is_file($root . '/attempt-inputs/' . str_repeat('f', 64) . '.job.approval.json')) {
    throw new RuntimeException('Captured approval retention violated journal ownership');
}
if (!is_dir($root . '/attempts/' . $token) || !is_dir($root . '/config/' . $revision)
    || is_dir($root . '/attempts/' . str_repeat('b', 48)) || is_dir($root . '/config/' . $orphan)) {
    throw new RuntimeException('Artifact retention removed active evidence or retained orphaned files');
}
foreach (['a' => true, 'b' => false, 'c' => false, 'd' => true] as $id => $expected) {
    if (is_file($batches . '/' . str_repeat($id, 32) . '.json') !== $expected) { throw new RuntimeException('Batch retention authority error'); }
}
unset($state);
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
rmdir($root);
echo "PASS: bounded RAM artifacts and batch history preserve active references and unverified work\n";
