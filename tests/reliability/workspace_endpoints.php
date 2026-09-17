<?php
// Disposable container only; can run with /boot mounted read-only.
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable isolation.'); }
$plugin = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
require $plugin . '/php/workspace-summary.php';
function check_ui($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function ui_endpoint($name, $query = '') {
    global $plugin;
    $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'parse_str($argv[2], $_GET); $_SERVER["SCRIPT_FILENAME"]=$argv[1]; require $argv[1];', $plugin . '/php/' . $name, $query];
    $proc = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
    check_ui($code === 0 && $error === '', $name . ': ' . $error);
    return $out;
}
function ui_json($name, $query = '') {
    $output = ui_endpoint($name, $query);
    check_ui(preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s', $output, $match), 'Missing JSON: ' . $output);
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}
foreach (['settings.php','send-settings.php','migrate-datasets.php','snapshot-manager-page.php'] as $page) {
    $html = ui_endpoint($page);
    check_ui(substr_count($html, '<h1>') === 1, 'Legacy entry must render one shell: ' . $page);
    check_ui(!str_contains($html, '<iframe'), 'Legacy iframe returned');
}
check_ui(str_contains(ui_endpoint('workspace.php', 'section=../../bad'), '<h1>Overview</h1>'), 'Unknown route must use Overview');
check_ui(str_contains(ui_endpoint('workspace.php', 'section=main'), 'id="zfsas_settings_form"'), 'Legacy main alias');
$summary = ui_json('workspace-summary.php');
check_ui($summary['ok'] && !$summary['sources']['coordinator']['available'], 'Unavailable coordinator must be explicit');
check_ui(!is_dir('/var/run/zfs-autosnapshot-coordinator'), 'Read-only summary started coordinator');
zfsas_ops_ensure_storage_dirs();
$job = ['JOB_ID'=>'ui-fixture','JOB_TYPE'=>'send','STATE'=>'failed','PARENT_RUN_ID'=>'parent','SCHEDULE_JOB_ID'=>'schedule','SOURCE_ROOT'=>'tank/source','DESTINATION_ROOT'=>'tank/target','LAST_ERROR'=>'Receiver unavailable','REQUESTED_AT'=>gmdate('c')];
zfsas_ops_write_job_file_unlocked(zfsas_ops_jobs_dir() . '/ui-fixture.job', $job);
@mkdir(zfsas_migrate_plugin_dir(), 0775, true);
file_put_contents(zfsas_migrate_status_file(), "STATE=\"migrating\"\nPID=\"99999999\"\nDATASET=\"tank/migrate\"\n");
$before = hash_file('sha256', zfsas_migrate_status_file());
$summary = ui_json('workspace-summary.php');
$operations = array_column($summary['operations'], null, 'id');
check_ui(isset($operations['replication:ui-fixture']), 'Replication missing');
check_ui($operations['replication:ui-fixture']['parentId'] === 'parent', 'Child relationship lost');
check_ui(in_array('retry', $operations['replication:ui-fixture']['actions'], true), 'Failed send retry missing');
check_ui($operations['migration:current']['recoveryRequired'], 'Stale migration must need recovery');
$status = ui_json('migrate-datasets-status.php', 'mode=runtime&dataset=tank/migrate');
check_ui($status['status']['isStale'] && !isset($status['datasets']) && !isset($status['docker']), 'Runtime polling must not inspect inventories');
check_ui(hash_file('sha256', zfsas_migrate_status_file()) === $before, 'Status polling rewrote state');
for ($i = 0; $i < 130; $i++) {
    $completed = array_merge($job, ['JOB_ID'=>'old-' . $i, 'STATE'=>'complete', 'REQUESTED_EPOCH'=>(string) (time() - $i)]);
    zfsas_ops_write_job_file_unlocked(zfsas_ops_jobs_dir() . '/old-' . $i . '.job', $completed);
}
$active = array_merge($job, ['JOB_ID'=>'active-old', 'STATE'=>'running', 'REQUESTED_EPOCH'=>'1']);
zfsas_ops_write_job_file_unlocked(zfsas_ops_jobs_dir() . '/active-old.job', $active);
$bounded = ui_json('workspace-summary.php');
check_ui(count($bounded['operations']) <= 120, 'Summary exceeded response bound');
check_ui(in_array('replication:active-old', array_column($bounded['operations'], 'id'), true), 'Old active work was hidden by completed records');
$unknown = ui_json('workspace-log.php', 'type=../../boot/config/super.dat');
check_ui(!$unknown['ok'], 'Log path allowlist bypass');
file_put_contents('/var/log/zfs_autosnapshot.last.log', str_repeat("bounded log\n", 10000));
$log = ui_json('workspace-log.php');
check_ui($log['ok'] && strlen($log['content']) <= 131072 && substr_count($log['content'], "\n") <= 200, 'Log response is not bounded');
echo "PASS: legacy routes, allowlisted shell/logs, unavailable sources, send identities, stale migration, read-only runtime polling and bounded logs\n";
