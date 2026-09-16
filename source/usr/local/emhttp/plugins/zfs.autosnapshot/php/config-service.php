<?php
function zfsas_auto_defaults()
{
    return ['DATASETS' => '', 'PREFIX' => 'autosnapshot-', 'DRY_RUN' => '0',
        'KEEP_ALL_FOR_DAYS' => '14', 'KEEP_DAILY_UNTIL_DAYS' => '30', 'KEEP_WEEKLY_UNTIL_DAYS' => '183',
        'SCHEDULE_MODE' => 'disabled', 'SCHEDULE_EVERY_MINUTES' => '15', 'SCHEDULE_EVERY_HOURS' => '1',
        'SCHEDULE_DAILY_HOUR' => '3', 'SCHEDULE_DAILY_MINUTE' => '0', 'SCHEDULE_WEEKLY_DAY' => '0',
        'SCHEDULE_WEEKLY_HOUR' => '3', 'SCHEDULE_WEEKLY_MINUTE' => '0', 'CUSTOM_CRON_SCHEDULE' => '', 'CRON_SCHEDULE' => ''];
}

function zfsas_config_revision($dir)
{
    return hash('sha256', (string) @file_get_contents($dir . '/zfs_autosnapshot.conf') . "\0" . (string) @file_get_contents($dir . '/zfs_send.conf'));
}

function zfsas_tuning_defaults($kind)
{
    $defaults = $kind === 'send' ? zfsas_send_defaults() : zfsas_auto_defaults();
    $result = [];
    foreach ($defaults as $key => $value) {
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
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $lock = @fopen($dir . '/config.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { $result['errors'][] = 'Unable to lock configuration.'; return $result; }
    try {
        $auto = zfsas_send_parse_config_file($dir . '/zfs_autosnapshot.conf', zfsas_auto_defaults());
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
        $prefixes = zfsas_known_send_prefixes($dir);
        $prefixes[] = $sendPrefix;
        if (zfsas_send_write_config_atomically($dir . '/send-prefix-history', implode("\n", array_unique($prefixes)) . "\n") === false) {
            $result['errors'][] = 'Unable to preserve replication checkpoint prefix history.'; return $result;
        }
        $file = $dir . ($kind === 'auto' ? '/zfs_autosnapshot.conf' : '/zfs_send.conf');
        if (zfsas_send_write_config_atomically($file, $render($submitted)) === false) {
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

function zfsas_config_read_pair($dir)
{
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $lock = @fopen($dir . '/config.lock', 'c');
    if (!$lock || !flock($lock, LOCK_SH)) { throw new RuntimeException('Unable to read configuration under its lock.'); }
    try {
        return ['auto' => zfsas_send_parse_config_file($dir . '/zfs_autosnapshot.conf', zfsas_auto_defaults()),
            'send' => zfsas_send_parse_config_file($dir . '/zfs_send.conf', zfsas_send_defaults()),
            'revision' => zfsas_config_revision($dir)];
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
