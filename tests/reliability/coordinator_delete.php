<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
require_once $plugin . '/php/coordinator-socket.php';
require_once $plugin . '/php/send-queue-helpers.php';
require_once $plugin . '/php/coordinator-delete.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function request($action) {
    $reply = zfsas_coordinator_request(['action' => $action]);
    if (!$reply['ok']) { throw new RuntimeException($reply['error']); }
    return $reply['result'];
}
function until($predicate): void {
    for ($i=0; $i<400; $i++) { if ($predicate()) { return; } usleep(20000); }
    throw new RuntimeException('Delete fixture timed out: ' . @file_get_contents('/tmp/delete-daemon.log'));
}
$dir = '/boot/config/plugins/zfs.autosnapshot'; mkdir($dir, 0775, true);
file_put_contents($dir . '/zfs_autosnapshot.conf', "SCHEDULE_MODE=\"disabled\"\n");
file_put_contents($dir . '/zfs_send.conf', '');
@mkdir('/usr/local/sbin', 0755, true);
$script = <<<'BASH'
#!/bin/bash
set -euo pipefail
if [[ ! -e /tmp/delete-first-started ]]; then
  touch /tmp/delete-first-started
  while [[ ! -e /tmp/delete-first-exit ]]; do sleep .02; done
else
  rm /tmp/zfs-autosnapshot-ops/delete-queue.inbox
  touch /tmp/delete-second-finished
fi
BASH;
file_put_contents('/usr/local/sbin/zfs_autosnapshot_delete_worker', $script);
chmod('/usr/local/sbin/zfs_autosnapshot_delete_worker', 0755);
@mkdir(zfsas_ops_root_dir(), 0770, true);
$process = proc_open([PHP_BINARY, $plugin . '/php/coordinator-daemon.php'], [0=>['file','/dev/null','r'],
    1=>['file','/tmp/delete-daemon.log','a'], 2=>['file','/tmp/delete-daemon.log','a']], $pipes);
try {
    until(function () { try { request('status'); return true; } catch (RuntimeException $e) { return false; } });
    $receipt = request('delete');
    until(fn() => is_file('/tmp/delete-first-started'));
    file_put_contents(zfsas_ops_delete_queue_inbox_path(), "late publication\n");
    check(request('delete')['runId'] === $receipt['runId'], 'Active deletion request launched another pump');
    touch('/tmp/delete-first-exit');
    until(fn() => is_file('/tmp/delete-second-finished'));
    until(function () use ($receipt) {
        foreach (request('status')['runs'] as $run) {
            if ($run['id'] === $receipt['runId']) { return $run['state'] === 'complete' && $run['taskStatus'][0]['attemptCount'] === 0; }
        }
        return false;
    });
    $envelope = json_decode(file_get_contents('/tmp/zfs-autosnapshot-coordinator/checkpoint.json'), true);
    $state = json_decode($envelope['payload'], true);
    check(count($state['runs']) === 1 && count($state['attempts']) === 2, 'Late publication lost same-run ownership');
    foreach ($state['attempts'] as $attempt) { check($attempt['state'] === 'stopped', 'Deletion run completed before verified shutdown'); }
    // Interrupted inbox draining and queued state also require another attempt.
    $processing = zfsas_ops_delete_queue_inbox_path() . '.processing.123';
    file_put_contents($processing, 'unfinished');
    check(zfsas_coordinator_delete_outcome(0)['outcome'] === 'wait', 'Stranded processing input lost');
    unlink($processing);
    @mkdir(zfsas_ops_status_dir(), 0770, true);
    file_put_contents(zfsas_ops_delete_queue_state_path(), "PENDING_COUNT=1\n");
    check(zfsas_coordinator_delete_outcome(0)['outcome'] === 'wait', 'Queued state lost after graceful stop');
    check(zfsas_coordinator_delete_outcome(1)['outcome'] === 'transient_failure', 'Crash bypassed retry policy');
    echo "PASS: coalesced deletion launch, late enqueue at worker exit, verified shutdown, same-run restart without consuming retries, stranded inbox and queued state\n";
} finally { proc_terminate($process, 9); proc_close($process); }
