<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/response-helpers.php';
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/send-helpers.php';
// Run only in the disposable test container: endpoints intentionally use production paths.
if (!file_exists('/.dockerenv')) { throw new RuntimeException('Run this endpoint test in a disposable container.'); }
$dir = '/boot/config/plugins/zfs.autosnapshot';
@mkdir($dir, 0775, true);
$base = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php');
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
    file_put_contents($dir . '/zfs_autosnapshot.conf', 'PREFIX="' . $auto . '"' . "\n");
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
setup_prefixes('snap-', 'snap-send-');
$response = finish_endpoint(start_endpoint('save-send-settings.php', ['send_snapshot_prefix' => 'send-', 'config_revision' => zfsas_config_revision($dir)]));
check(!empty($response['saved']), 'Existing invalid configuration cannot be corrected');
check(array_key_exists('schedulerApplied', $response), 'Scheduler result is not distinguished from saved config');
unlink($runner);
echo "PASS: real save endpoints, all prefix overlap directions, safe stems, stale/concurrent saves, conflict repair, prefix history\n";
