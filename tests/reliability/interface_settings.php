<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/interface-settings.php';
function verify($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$dir = sys_get_temp_dir() . '/snapsync-interface-test-' . bin2hex(random_bytes(5));
try {
    $initial = zfsas_interface_read($dir);
    verify(!$initial['enabled'] && !is_dir($dir), 'Reading defaults wrote configuration');
    zfsas_interface_save($dir, false, $initial['revision']);
    verify(!is_dir($dir), 'Saving unchanged defaults wrote configuration');
    $enabled = zfsas_interface_save($dir, true, $initial['revision']);
    verify($enabled['enabled'], 'Enable failed');
    $inode = fileinode($dir . '/show-tab');
    zfsas_interface_save($dir, true, $enabled['revision']);
    clearstatcache();
    verify(fileinode($dir . '/show-tab') === $inode, 'Identical save replaced configuration');
    try { zfsas_interface_save($dir, false, $initial['revision']); throw new Exception('Stale save accepted'); }
    catch (RuntimeException $expected) { verify(strpos($expected->getMessage(), 'changed') !== false, 'Unexpected stale error'); }
    verify(zfsas_interface_read($dir)['enabled'], 'Stale save changed state');
    $page = file_get_contents(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/ZFSSnapSyncTab.page');
    $header = parse_ini_string(explode('---', $page)[0]);
    verify($header['Menu'] === 'Tasks:85' && $header['Name'] === 'ZFS SnapSync', 'Top-level tab missing or mislabeled');
    $condition = str_replace('/boot/config/plugins/zfs.snapsync', $dir, $header['Cond']);
    verify(eval('return ' . $condition . ';') === true, 'Enabled tab hidden');
    zfsas_interface_save($dir, false, $enabled['revision']);
    verify(eval('return ' . $condition . ';') === false, 'Disabled tab visible');
    echo "PASS: interface preference read-only defaults, atomic saves, unchanged writes, revision conflicts, and tab visibility\n";
} finally { @unlink($dir . '/show-tab'); @rmdir($dir); }

$endpoint = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/save-interface-settings.php');
foreach (['GET', 'POST'] as $method) {
    $code = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . '; require ' . var_export($endpoint, true) . ';';
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s', $output, $match);
    $result = json_decode($match[1] ?? '', true);
    verify(isset($result['ok']) && !$result['ok'], 'Endpoint accepted GET or missing CSRF');
}
echo "PASS: interface endpoint rejects GET and missing CSRF\n";
