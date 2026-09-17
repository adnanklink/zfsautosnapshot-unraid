<?php
// Real RAM manifest publication and an actual SIGKILL at the ZFS boundary.
if (!is_file('/.dockerenv')) { throw new RuntimeException('Use the disposable test container.'); }
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/snapshot-manager-helpers.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
if (($argv[1] ?? '') === 'attempt') {
    $batch = zfsas_sm_read_json_file(zfsas_sm_batch_path($argv[2]));
    putenv('BATCH_TEST_PID=' . getmypid());
    $lock = zfsas_sm_batch_lock($batch['token']);
    zfsas_sm_execute_item($batch, $batch['items'][0], []);
    fclose($lock);
    exit;
}
$fixture = '/tmp/batch-item-recovery-' . bin2hex(random_bytes(6));
mkdir($fixture, 0775, true);
file_put_contents($fixture . '/zfs', <<<'PY'
#!/usr/bin/python3
import os, json, signal, sys
root=os.environ['BATCH_TEST_ROOT']
batch=json.load(open(os.environ['BATCH_TEST_MANIFEST']))
assert batch['items'][0]['state']=='running', 'missing committed intent'
with open(root+'/actions','a') as out: out.write(' '.join(sys.argv[1:])+'\n')
if os.path.exists(root+'/kill'): os.kill(int(os.environ['BATCH_TEST_PID']),signal.SIGKILL)
PY);
chmod($fixture . '/zfs', 0755);
putenv('PATH=' . $fixture . ':' . getenv('PATH'));
putenv('BATCH_TEST_ROOT=' . $fixture);
$batch = zfsas_sm_new_batch('tank/data', 'take_snapshot');
$batch['state'] = 'running'; $batch['approvedAt'] = time();
$batch['items'] = [['snapshot'=>'tank/data@test', 'guid'=>'', 'identity'=>'tank/data@test#', 'state'=>'queued']];
zfsas_sm_batch_store($batch);
$path = zfsas_sm_batch_path($batch['token']);
putenv('BATCH_TEST_MANIFEST=' . $path);
touch($fixture . '/kill');
$child = proc_open([PHP_BINARY, __FILE__, 'attempt', $batch['token']], [1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes);
check(is_resource($child), 'Unable to launch attempt');
proc_close($child);
$batch = zfsas_sm_read_json_file($path);
check($batch['items'][0]['state'] === 'running', 'Interrupted item lost its intent');
check(count(file($fixture . '/actions')) === 1, 'Mutation was not exercised');
unlink($fixture . '/kill');
zfsas_sm_execute_item($batch, $batch['items'][0], []);
$batch = zfsas_sm_read_json_file($path);
check($batch['items'][0]['state'] === 'failed' && $batch['items'][0]['recoveryRequired'], 'Ambiguous outcome must require review');
check(count(file($fixture . '/actions')) === 1, 'Interrupted mutation repeated');
check(zfsas_sm_batch_payload($batch)['recoveryRequired'], 'Status omitted recovery review');
// A completed item is committed immediately and cannot be executed twice.
$batch['items'][0] = ['snapshot'=>'tank/data@next', 'guid'=>'', 'identity'=>'tank/data@next#', 'state'=>'queued'];
zfsas_sm_execute_item($batch, $batch['items'][0], []);
$batch = zfsas_sm_read_json_file($path);
check($batch['items'][0]['state'] === 'completed', 'Success was not published');
zfsas_sm_execute_item($batch, $batch['items'][0], []);
check(count(file($fixture . '/actions')) === 2, 'Completed mutation repeated');
// Rollback must never be replayed after an interrupted result publication.
$batch['action'] = 'rollback'; $batch['items'][0]['state'] = 'running';
zfsas_sm_execute_item($batch, $batch['items'][0], []);
check($batch['items'][0]['recoveryRequired'] && count(file($fixture . '/actions')) === 2, 'Rollback replay was allowed');
// A failed intent publication must prevent any operation.
@unlink($path); mkdir($path);
$batch['action'] = 'take_snapshot'; $batch['items'][0]['state'] = 'queued';
$blocked = false;
try { @zfsas_sm_execute_item($batch, $batch['items'][0], []); }
catch (RuntimeException $error) { $blocked = true; }
check($blocked && count(file($fixture . '/actions')) === 2, 'Mutation started despite publication failure');
rmdir($path); @unlink($path . '.lock');
foreach (glob($fixture . '/*') as $file) { unlink($file); } rmdir($fixture);
echo "PASS: published batch item intent, SIGKILL recovery, no repeated mutation or rollback, immediate result publication\n";
