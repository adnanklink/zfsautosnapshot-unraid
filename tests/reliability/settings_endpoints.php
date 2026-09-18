<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/response-helpers.php';
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-helpers.php';
// Run only in the disposable test container: endpoints intentionally use production paths.
if (!file_exists('/.dockerenv')) { throw new RuntimeException('Run this endpoint test in a disposable container.'); }
$dir = '/boot/config/plugins/zfs.snapsync';
@mkdir($dir, 0775, true);
$base = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
$runner = tempnam('/tmp', 'endpoint-');
file_put_contents($runner, '<?php $GLOBALS["csrf_token"]="fixture"; $_SERVER["REQUEST_METHOD"]="POST"; $_POST=json_decode(base64_decode($argv[2]),true); require $argv[1];');
function start_endpoint($name, $post) {
    global $base, $runner;
    $post += ['csrf_token' => 'fixture', 'ajax' => 'save'];
    $pipes = [];
    $process = proc_open([PHP_BINARY, $runner, $base . '/' . $name, base64_encode(json_encode($post))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$process, $pipes];
}
function finish_endpoint($request) {
    [$process, $pipes] = $request;
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    if (!preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s', $output, $match)) {
        throw new RuntimeException('Invalid endpoint response: ' . $output . $error);
    }
    return json_decode($match[1], true);
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function setup_prefixes($auto, $send) {
    global $dir;
    file_put_contents($dir . '/zfs_snapsync.conf', 'PREFIX="' . $auto . '"' . "\n");
    file_put_contents($dir . '/zfs_send.conf', 'SEND_SNAPSHOT_PREFIX="' . $send . '"' . "\n");
}
foreach ([['snap-', 'snap-'], ['snap-', 'snap-send-'], ['snap-send-', 'snap-']] as [$auto, $send]) {
    setup_prefixes($auto, 'other-');
    $response = finish_endpoint(start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => $send, 'config_revision' => zfsas_config_revision($dir)]));
    check(!$response['ok'] && str_contains(implode(' ', $response['errors']), 'overlaps'), 'Send endpoint allowed prefix overlap');
    setup_prefixes('other-', $send);
    $response = finish_endpoint(start_endpoint('save-settings.php', ['prefix' => $auto, 'config_revision' => zfsas_config_revision($dir), 'dataset_name' => ['tank/data']]));
    check(!$response['ok'] && str_contains(implode(' ', $response['errors']), 'overlaps'), 'Auto endpoint allowed prefix overlap');
}
setup_prefixes('snap-auto-', 'snap-send-');
$revision = zfsas_config_revision($dir);
$a = start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => 'safe-a-', 'config_revision' => $revision]);
$b = start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => 'safe-b-', 'config_revision' => $revision]);
$results = [finish_endpoint($a), finish_endpoint($b)];
check(count(array_filter($results, fn($r) => !empty($r['saved']))) === 1, 'Simultaneous stale saves were not serialized');
check(count(array_filter($results, fn($r) => str_contains(implode(' ', $r['errors']), 'revision'))) === 1, 'Stale save did not report revision conflict');
check(count(zfsas_known_send_prefixes($dir)) >= 2, 'Old checkpoint prefix protection lost');
setup_prefixes('auto-', 'send-');
$revision = zfsas_config_revision($dir);
$a = start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => 'shared-', 'config_revision' => $revision]);
$b = start_endpoint('save-settings.php', ['prefix' => 'shared-auto-', 'config_revision' => $revision, 'dataset_name' => ['tank/data'], 'dataset_selected' => ['1'], 'dataset_threshold' => ['100G']]);
$results = [finish_endpoint($a), finish_endpoint($b)];
check(count(array_filter($results, fn($r) => !empty($r['saved']))) === 1, 'Concurrent saves across pages did not serialize');
setup_prefixes('snap-', 'snap-send-');
$response = finish_endpoint(start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => 'send-', 'config_revision' => zfsas_config_revision($dir)]));
check(!empty($response['saved']), 'Existing invalid configuration cannot be corrected');
check(array_key_exists('schedulerApplied', $response), 'Scheduler result is not distinguished from saved config');
// Send calendar options use the same revision-checked atomic endpoint.
$response = finish_endpoint(start_endpoint('save-send-settings.php', ['config_revision'=>zfsas_config_revision($dir),
    'new_job_source'=>'tank/data', 'new_job_destination'=>'backup/data', 'new_job_frequency'=>'1d', 'new_job_threshold'=>'1G', 'new_job_time'=>'23:17']));
check(!empty($response['saved']), 'New calendar send save failed: '.json_encode($response));
$config=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());
$id=zfsas_send_job_id('tank/data','backup/data'); $specs=json_decode($config['SEND_SCHEDULE_SPECS'],true);
check($specs[$id]['kind']==='daily' && $specs[$id]['hour']===23 && $specs[$id]['minute']===17,'Saved calendar controls lost');
$post=['job_id'=>[$id], 'job_source'=>['tank/data'], 'job_destination'=>['backup/data'], 'job_frequency'=>['1d'], 'job_threshold'=>['2G']];
$response=finish_endpoint(start_endpoint('save-send-settings.php',$post+['config_revision'=>zfsas_config_revision($dir)]));
check(!empty($response['saved']),'Unrelated send save failed');
$config=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());
check(json_decode($config['SEND_SCHEDULE_SPECS'],true)===$specs,'Unrelated endpoint save shifted time or anchor');
$before=file_get_contents($dir.'/zfs_send.conf');
$response=finish_endpoint(start_endpoint('save-send-settings.php',$post+['job_time'=>['25:00'],'config_revision'=>zfsas_config_revision($dir)]));
check(empty($response['saved']) && file_get_contents($dir.'/zfs_send.conf')===$before,'Invalid calendar time changed config');
$response=finish_endpoint(start_endpoint('save-send-settings.php',$post+['job_cleanup_policy'=>['older_anchors'],'config_revision'=>zfsas_config_revision($dir)]));
check(!empty($response['saved']),'Explicit anchor policy save failed');
$config=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());
check(zfsas_send_cleanup_mode($config,['id'=>$id,'transport'=>'local'])==='older_anchors','Saved anchor authorization lost');
$response=finish_endpoint(start_endpoint('save-send-settings.php',$post+['config_revision'=>zfsas_config_revision($dir)]));
check(!empty($response['saved']),'Unrelated policy save failed');
$config=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());
check(zfsas_send_cleanup_mode($config,['id'=>$id,'transport'=>'local'])==='older_anchors','Unrelated save changed authorization');
$before=file_get_contents($dir.'/zfs_send.conf');
$response=finish_endpoint(start_endpoint('save-send-settings.php',$post+['job_cleanup_policy'=>['unsafe'],'config_revision'=>zfsas_config_revision($dir)]));
check(empty($response['saved']) && file_get_contents($dir.'/zfs_send.conf')===$before,'Invalid cleanup mode wrote configuration');
unlink($runner);
echo "PASS: real save endpoints, all prefix overlap directions, safe stems, stale/concurrent saves, conflict repair, prefix history\n";
