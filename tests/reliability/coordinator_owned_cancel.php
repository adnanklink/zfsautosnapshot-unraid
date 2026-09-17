<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$root = '/tmp/owned-cancel-' . bin2hex(random_bytes(6));
$journal = new ZfsasCoordinatorState($root); $transitions = [];
$executor = new ZfsasCoordinatorExecutor($journal, $root, $root . '/runtime',
    fn($task) => ['/bin/sleep', $task['parameters']['duration'] ?? '0'],
    fn($task, $code) => ['outcome'=>$code === 0 ? 'success' : 'transient_failure'], [],
    static function($id) use (&$transitions, $journal) { $transitions[$id] = $journal->state['tasks'][$id]['state']; });
$parent = $journal->submit('parent', ['manual'=>true, 'tasks'=>['items'=>['kind'=>'batch', 'parameters'=>['batch'=>['action'=>'delete']]]]], time())['runId'];
$journal->state['tasks'][$parent . ':items']['state'] = 'waiting';
$journal->state['tasks'][$parent . ':items']['blocked'] = 'dependency'; $journal->commit();
$children = [];
for ($i = 0; $i < 2; $i++) {
    $children[] = $journal->submit('child-' . $i, ['tasks'=>['delete'=>['kind'=>'delete',
        'parameters'=>['ownerRunId'=>$parent, 'duration'=>'60']]]], time())['runId'];
}
$unrelated = $journal->submit('unrelated', ['tasks'=>['delete'=>['kind'=>'delete']]], time())['runId'];
function drive($executor, $predicate): void {
    for ($round = 0; $round < 400; $round++) {
        $executor->tick(hrtime(true)/1e9); if ($predicate()) { return; } usleep(20000);
    }
    throw new RuntimeException('Cancellation fixture timed out');
}
try {
    drive($executor, fn() => $journal->state['tasks'][$children[0] . ':delete']['state'] === 'running');
    $old = $journal->state['attempts'][$journal->state['tasks'][$children[0] . ':delete']['attempt']];
    $executor->cancel($parent);
    check($journal->state['runs'][$parent]['state'] === 'canceling', 'Parent acknowledged shutdown with an active child');
    check($journal->state['tasks'][$children[0] . ':delete']['state'] === 'stopping', 'Running child retained execution authority');
    check(($transitions[$children[1] . ':delete'] ?? '') === 'canceled', 'Queued child cancellation was not projected');
    check($journal->state['runs'][$unrelated]['state'] === 'queued', 'Cancellation spread to unrelated work');
    drive($executor, fn() => $journal->state['runs'][$parent]['state'] === 'canceled' && $journal->state['runs'][$unrelated]['state'] === 'complete');
    check(ZfsasCoordinatorExecutor::members($old['pid'], $old['start']) === [], 'Parent released surviving child processes');
    $executor->cancel($parent);
    check($journal->state['runs'][$parent]['state'] === 'canceled', 'Repeated cancellation changed outcome');
    echo "PASS: parent cancellation revokes delegated work, waits for shutdown, projects queued cancellation and preserves unrelated work\n";
} finally {
    foreach ($journal->state['runs'] as $run) { $executor->cancel($run['id']); }
    for ($round=0; $round<120 && $journal->activeTaskIds(); $round++) { $executor->tick(hrtime(true)/1e9); usleep(20000); }
}
