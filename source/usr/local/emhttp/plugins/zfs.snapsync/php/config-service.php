<?php
require_once __DIR__ . "/schedule-spec.php";
require_once __DIR__ . "/send-schedule.php";
function zfsas_auto_defaults()
{
    return ['DATASETS' => '', 'PREFIX' => 'snapsync-auto-', 'DRY_RUN' => '0',
        'KEEP_ALL_FOR_DAYS' => '14', 'KEEP_DAILY_UNTIL_DAYS' => '30', 'KEEP_WEEKLY_UNTIL_DAYS' => '183',
        'SCHEDULE_SPEC' => '', 'SCHEDULE_MODE' => 'disabled', 'SCHEDULE_EVERY_MINUTES' => '15', 'SCHEDULE_EVERY_HOURS' => '1',
        'SCHEDULE_DAILY_HOUR' => '3', 'SCHEDULE_DAILY_MINUTE' => '0', 'SCHEDULE_WEEKLY_DAY' => '0',
        'SCHEDULE_WEEKLY_HOUR' => '3', 'SCHEDULE_WEEKLY_MINUTE' => '0', 'CUSTOM_CRON_SCHEDULE' => '', 'CRON_SCHEDULE' => ''];
}

// A per-configuration lock on RAM, shared with sync-cron.sh. Reading settings
// must never create a file or change directory metadata on the boot device.
function zfsas_config_lock($dir)
{
    $root = '/tmp/zfs-snapsync-config-locks';
    if (!is_dir($root)) { @mkdir($root, 0775, true); }
    @chown($root, 'nobody'); @chgrp($root, 'users');
    $path = $root . '/' . hash('sha256', $dir) . '.lock';
    $lock = @fopen($path, 'c');
    @chmod($path, 0660); @chown($path, 'nobody'); @chgrp($path, 'users');
    return $lock;
}

function zfsas_config_revision($dir)
{
    return hash('sha256', (string) @file_get_contents($dir . '/zfs_snapsync.conf') . "\0" . (string) @file_get_contents($dir . '/zfs_send.conf'));
}

function zfsas_tuning_defaults($kind)
{
    $defaults = $kind === 'send' ? zfsas_send_defaults() : zfsas_auto_defaults();
    $result = [];
    foreach ($defaults as $key => $value) {
        if ($key === 'SCHEDULE_SPEC') { continue; }
        if (strpos($key, 'KEEP_') === 0 || strpos($key, 'SEND_KEEP_') === 0
            || ($kind === 'auto' && (strpos($key, 'SCHEDULE_') === 0 || $key === 'CUSTOM_CRON_SCHEDULE'))
            || in_array($key, ['SEND_MAX_PARALLEL', 'SEND_RATE_LIMIT', 'SEND_PREP_EXTRA_WORKERS'], true)) {
            $result[strtolower($key)] = $value;
        }
    }
    return $result;
}

function zfsas_known_send_prefixes($dir)
{
    $current = zfsas_send_parse_config_file($dir . '/zfs_send.conf', zfsas_send_defaults());
    $history = @file($dir . '/send-prefix-history', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $history[] = $current['SEND_SNAPSHOT_PREFIX'];
    return array_values(array_unique(array_filter($history, function ($value) { return preg_match('/^[A-Za-z0-9._:-]+$/', $value); })));
}

// The counterpart prefix and revision are always read here under one lock. No
// endpoint can bypass validation by omitting the other page's prefix.
function zfsas_config_save($kind, $dir, array $submitted, $revision, $render, $syncScript)
{
    $result = ['saved' => false, 'schedulerApplied' => false, 'errors' => [], 'notices' => [], 'revision' => zfsas_config_revision($dir)];
    $lock = zfsas_config_lock($dir);
    if (!$lock || !flock($lock, LOCK_EX)) { $result['errors'][] = 'Unable to lock configuration.'; return $result; }
    try {
        $auto = zfsas_send_parse_config_file($dir . '/zfs_snapsync.conf', zfsas_auto_defaults());
        $send = zfsas_send_parse_config_file($dir . '/zfs_send.conf', zfsas_send_defaults());
        $result['revision'] = zfsas_config_revision($dir);
        if (!is_string($revision) || !hash_equals($result['revision'], $revision)) {
            $result['errors'][] = 'Settings changed or the revision is missing. Reload both configuration pages before saving.';
            return $result;
        }
        $autoPrefix = $kind === 'auto' ? $submitted['PREFIX'] : $auto['PREFIX'];
        $sendPrefix = $kind === 'send' ? $submitted['SEND_SNAPSHOT_PREFIX'] : $send['SEND_SNAPSHOT_PREFIX'];
        if ($autoPrefix === '' || $sendPrefix === '' || zfsas_snapshot_prefixes_conflict($autoPrefix, $sendPrefix)) {
            $result['errors'][] = zfsas_snapshot_prefix_conflict_message($autoPrefix, $sendPrefix);
            return $result;
        }
        if ($kind === 'auto') {
            try {
                $spec = ZfsasSchedule::autoSave($auto, $submitted, !empty($submitted['__convert_schedule']), time());
                $submitted['SCHEDULE_SPEC'] = json_encode($spec, JSON_THROW_ON_ERROR);
                $result['schedulePreview'] = ZfsasSchedule::preview($spec, time(), ZfsasSchedule::hostTimezone());
            } catch (InvalidArgumentException | JsonException $error) { $result['errors'][] = $error->getMessage(); return $result; }
        }
        if ($kind === 'send') {
            try {
                $submitted['SEND_CLEANUP_POLICIES'] = zfsas_send_cleanup_save($submitted, zfsas_send_parse_jobs($submitted['SEND_JOBS'] ?? ''), []);
                $submitted['SEND_SCHEDULE_SPECS'] = zfsas_send_schedule_specs_save($send, $submitted, $submitted['__schedule_options'] ?? [], time()); }
            catch (InvalidArgumentException | JsonException $error) { $result['errors'][] = $error->getMessage(); return $result; }
        }
        $prefixes = zfsas_known_send_prefixes($dir);
        $prefixes[] = $sendPrefix;
        if (zfsas_send_write_config_atomically($dir . '/send-prefix-history', implode("\n", array_unique($prefixes)) . "\n") === false) {
            $result['errors'][] = 'Unable to preserve replication checkpoint prefix history.'; return $result;
        }
        $file = $dir . ($kind === 'auto' ? '/zfs_snapsync.conf' : '/zfs_send.conf');
        $content = $render($submitted);
        if ($kind === 'send') { $content = rtrim($content, "\n") . "\nSEND_SCHEDULE_SPECS=" . zfsas_send_quote_config_string($submitted['SEND_SCHEDULE_SPECS']) . "\n"; }
        if ($kind === 'auto') { $content = rtrim($content, "\n") . "\nSCHEDULE_SPEC=" . zfsas_send_quote_config_string($submitted['SCHEDULE_SPEC']) . "\n"; }
        if (zfsas_send_write_config_atomically($file, $content) === false) {
            $result['errors'][] = 'Unable to write configuration atomically.'; return $result;
        }
        $result['saved'] = true;
        $result['revision'] = zfsas_config_revision($dir);
        $output = []; $exit = 0;
        // sync-cron inherits this transaction's lock; standalone invocations lock themselves.
        exec('ZFSAS_CONFIG_LOCK_HELD=1 ' . escapeshellarg($syncScript) . ' 2>&1', $output, $exit);
        $result['schedulerApplied'] = $exit === 0;
        $result['notices'][] = $exit === 0 ? 'Settings saved and scheduler applied.' : 'Settings saved; scheduler application failed: ' . implode(' | ', $output);
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function zfsas_config_read_pair($dir, $nonblocking = false)
{
    $lock = zfsas_config_lock($dir);
    if (!$lock) { throw new RuntimeException('Unable to open configuration lock.'); }
    if (!flock($lock, LOCK_SH | ($nonblocking ? LOCK_NB : 0))) {
        fclose($lock);
        if ($nonblocking) { return null; }
        throw new RuntimeException('Unable to read configuration under its lock.');
    }
    try {
        return ['auto' => zfsas_send_parse_config_file($dir . '/zfs_snapsync.conf', zfsas_auto_defaults()),
            'send' => zfsas_send_parse_config_file($dir . '/zfs_send.conf', zfsas_send_defaults()),
            'revision' => zfsas_config_revision($dir),
            'rawAuto' => (string) @file_get_contents($dir . '/zfs_snapsync.conf'),
            'rawSend' => (string) @file_get_contents($dir . '/zfs_send.conf'),
            'prefixHistory' => (string) @file_get_contents($dir . '/send-prefix-history')];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function zfsas_config_tools_markup($kind, $dir, $pair = null)
{
    $pair = $pair ?? zfsas_config_read_pair($dir);
    $other = $kind === 'send' ? $pair['auto']['PREFIX'] : $pair['send']['SEND_SNAPSHOT_PREFIX'];
    $options = ['defaults' => zfsas_tuning_defaults($kind), 'otherPrefix' => $other,
        'prefixField' => $kind === 'send' ? 'send_snapshot_prefix' : 'prefix'];
    return '<input type="hidden" name="config_revision" value="' . $pair['revision'] . '">'
        . '<div data-config-tools="' . htmlspecialchars(json_encode($options), ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="button" class="btn" data-restore-tuning>Restore tuning defaults</button> '
        . '<span data-dirty role="status"></span><p data-prefix-feedback role="status"></p>'
        . '<p>Restore tuning defaults populates this form. Choose Save to apply.</p></div>';
}
