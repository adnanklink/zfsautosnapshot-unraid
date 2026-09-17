<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container with production plugin/sbin mounts.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin . '/snapshot-manager-helpers.php';
require $plugin . '/coordinator-socket.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$config = '/boot/config/plugins/zfs.snapsync'; @mkdir($config, 0775, true);
file_put_contents($config . '/zfs_snapsync.conf', "PREFIX=\"auto-\"\nDATASETS=\"\"\n");
file_put_contents($config . '/zfs_send.conf', "SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/var/local/emhttp',0775,true); file_put_contents('/var/local/emhttp/var.ini', 'mdState="STOPPED"');
$batch = zfsas_sm_new_batch('tank/data', 'delete'); $batch['approvedAt'] = time(); $batch['state'] = 'queued';
$batch['items'] = [];
for ($i=1; $i<=51; $i++) {
    $batch['items'][] = ['snapshot'=>'tank/data@auto-test' . $i, 'guid'=>(string) $i,
        'identity'=>'tank/data@auto-test' . $i . '#' . $i, 'candidate'=>true, 'state'=>'queued'];
}
zfsas_sm_batch_store($batch);
function startDaemon($plugin) {
    return proc_open([PHP_BINARY, $plugin . '/coordinator-daemon.php'],
        [0=>['file','/dev/null','r'],1=>['file','/tmp/cancel-daemon.log','a'],2=>['file','/tmp/cancel-daemon.log','a']], $pipes);
}
function waitFor($predicate): void {
    for ($i=0; $i<400; $i++) { if ($predicate()) { return; } usleep(20000); }
    throw new RuntimeException('Endpoint fixture timed out: ' . file_get_contents('/tmp/cancel-daemon.log'));
}
$daemon = startDaemon($plugin);
try {
    waitFor(static function() {
        try { return zfsas_coordinator_request(['action'=>'status'])['ok']; } catch (Throwable $error) { return false; }
    });
    $receipt = zfsas_coordinator_request(['action'=>'batch', 'dataset'=>'tank/data', 'token'=>$batch['token']]);
    check($receipt['ok'], 'Batch submission failed'); $runId = $receipt['result']['runId'];
    waitFor(static function() { return count(ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator')['runs']) === 51; });
    require $plugin . '/workspace-summary.php';
    $operations = array_column(zfsas_workspace_summary()['operations'], null, 'nativeId');
    check(in_array('cancel', $operations[$runId]['actions'], true), 'Activity omitted batch cancellation');
    $decision = zfsas_ops_control_path('cancelled', $runId);
    mkdir($decision, 0775, true); // Force atomic publication failure.
    $rejected = zfsas_coordinator_request(['action'=>'cancel', 'runId'=>$runId]);
    check(!$rejected['ok'], 'Cancellation acknowledged a failed persistent write');
    $state = ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');
    check(!in_array($state['runs'][$runId]['state'], ['canceling','canceled'], true), 'Runtime cancellation preceded persistence');
    rmdir($decision);
    $response = zfsas_coordinator_request(['action'=>'cancel', 'runId'=>$runId]);
    check($response['ok'] && $response['result']['cancellationCommitted'], 'Batch cancellation endpoint rejected request');
    $decision = zfsas_ops_control_path('cancelled', $runId);
    check(is_file($decision), 'Acknowledged cancellation without persistent decision');
    check(!is_file(zfsas_ops_control_path('paused', 'auto')), 'Manual batch cancellation paused Auto Snapshot');
    waitFor(static function() { $s = ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator'); return !array_filter($s['runs'], fn($run) => $run['state'] !== 'canceled'); });
    $manifest = zfsas_sm_read_json_file(zfsas_sm_batch_path($batch['token']));
    check($manifest['state'] === 'canceled' && count($manifest['items']) === 51, 'Canceled batch projection lost membership');
    check(!array_filter($manifest['items'], fn($item) => in_array($item['state'], ['queued','running','deleting'], true)), 'Canceled selections still appear active');
    $before = file_get_contents($decision); $mtime = filemtime($decision);
    $again = zfsas_coordinator_request(['action'=>'cancel', 'runId'=>$runId]);
    clearstatcache(true, $decision);
    check($again['ok'] && $again['result']['shutdownComplete'] && file_get_contents($decision) === $before && filemtime($decision) === $mtime, 'Repeated cancellation rewrote or lost the decision');
    proc_terminate($daemon, 9); proc_close($daemon); $daemon = startDaemon($plugin);
    waitFor(static function() {
        try { return zfsas_coordinator_request(['action'=>'status'])['ok']; } catch (Throwable $error) { return false; }
    });
    $s = ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');
    check(count($s['runs']) === 51 && !array_filter($s['runs'], fn($run) => $run['state'] !== 'canceled'), 'Restart recreated canceled batch work');
    echo "PASS: actual batch cancellation endpoint, persistent decision, no unrelated pause, verified child shutdown and restart\n";
} finally { if ($daemon) { proc_terminate($daemon, 9); proc_close($daemon); } }
