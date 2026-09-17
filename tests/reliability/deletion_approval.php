<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin = __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/';
require $plugin . 'snapshot-manager-helpers.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$fixture = '/tmp/delete-approval-fixture'; mkdir($fixture);
file_put_contents($fixture . '/zfs', "#!/bin/sh\ncat /tmp/delete-approval-fixture/row\n");
$liveRow = ['tank/data@auto-test', '1', '0', '0', '0', '123', '1', '-'];
file_put_contents($fixture . '/row', implode("\t", $liveRow) . "\n");
chmod($fixture . '/zfs', 0755); putenv('PATH=' . $fixture . ':' . getenv('PATH'));
$task = 'run-fixture:delete'; putenv('ZFSAS_TASK_ID=' . $task);
$batch = zfsas_sm_new_batch('tank/data', 'delete'); $batch['approvedAt'] = time();
$item = ['identity'=>'tank/data@auto-test#123', 'snapshot'=>'tank/data@auto-test', 'guid'=>'123', 'candidate'=>true, 'state'=>'queued'];
$job = 'sm-' . $batch['token'] . '-' . substr(hash('sha256', $item['identity']), 0, 16);
$path = '/tmp/zfs-autosnapshot-coordinator/attempt-inputs/' . hash('sha256', $task) . '.job.approval.json';
mkdir(dirname($path), 0775, true); putenv('ZFSAS_DELETE_APPROVAL=' . $path);
$approval = ['version'=>1, 'taskId'=>$task, 'jobId'=>$job, 'batch'=>$batch, 'item'=>$item];
function probe($snapshot='tank/data@auto-test', $guid='123') {
    global $plugin, $job;
    $proc = proc_open([PHP_BINARY, $plugin . 'snapshot-delete-check.php', $job, $snapshot, $guid], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($proc), $out];
}
check(probe()[0] === 1, 'Missing capture accepted');
file_put_contents($path, json_encode($approval));
check(probe()[0] === 0, 'Valid captured approval required a status manifest: ' . probe()[1]);
zfsas_sm_batch_store(array_replace($batch, ['approvedAt'=>0, 'items'=>[]]));
check(probe()[0] === 0, 'Status manifest replaced journal authority');
check(probe('tank/data@auto-other')[0] === 1 && probe('tank/data@auto-test','999')[0] === 1, 'Captured selection expanded');
foreach ([4=>'1', 5=>'999', 7=>'tank/clone'] as $column=>$value) {
    $live = $liveRow; $live[$column] = $value;
    file_put_contents($fixture . '/row', implode("\t", $live) . "\n");
    check(probe()[0] === 1, 'Captured approval bypassed changed live metadata');
}
file_put_contents($fixture . '/row', implode("\t", $liveRow) . "\n");
foreach (['taskId'=>'different-task', 'jobId'=>'different-job', 'version'=>2] as $key=>$value) {
    file_put_contents($path, json_encode(array_replace($approval, [$key=>$value])));
    check(probe()[0] === 1, 'Mismatched capture accepted: ' . $key);
}
$changed = $approval; $changed['batch']['configRevision'] = str_repeat('f',64);
file_put_contents($path,json_encode($changed)); check(probe()[0] === 1,'Changed configuration accepted');
file_put_contents($path,json_encode($approval)); rename($path,$path.'.real'); symlink($path.'.real',$path);
check(probe()[0] === 1,'Symlink approval accepted');
echo "PASS: captured deletion approval, manifest independence, exact membership, task binding and changed-config rejection\n";
