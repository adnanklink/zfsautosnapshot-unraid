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
$state->prune($now);
zfsas_coordinator_prune_artifacts($state, $root, $batches, $now);
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
