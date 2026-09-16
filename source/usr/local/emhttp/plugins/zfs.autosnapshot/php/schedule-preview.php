<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/schedule-spec.php';
try {
    $pair = zfsas_config_read_pair('/boot/config/plugins/zfs.autosnapshot');
    if (isset($_GET['spec'])) {
        if (!is_string($_GET['spec']) || strlen($_GET['spec']) > 4096) { throw new InvalidArgumentException('Invalid schedule specification.'); }
        $spec = ZfsasSchedule::validate(json_decode($_GET['spec'], true, 32, JSON_THROW_ON_ERROR));
    } else {
        $submitted = $pair['auto'];
        foreach (['SCHEDULE_MODE', 'SCHEDULE_EVERY_MINUTES', 'SCHEDULE_EVERY_HOURS', 'SCHEDULE_DAILY_HOUR', 'SCHEDULE_DAILY_MINUTE', 'SCHEDULE_WEEKLY_DAY', 'SCHEDULE_WEEKLY_HOUR', 'SCHEDULE_WEEKLY_MINUTE', 'CUSTOM_CRON_SCHEDULE'] as $key) {
            $value = $_GET[strtolower($key)] ?? $submitted[$key];
            if (!is_string($value) || strlen($value) > 256) { throw new InvalidArgumentException('Invalid scheduling field.'); }
            $submitted[$key] = $value;
        }
        $spec = ZfsasSchedule::autoSave($pair['auto'], $submitted, ($_GET['convert_schedule'] ?? '') === '1', time());
    }
    zfsas_emit_marked_json(['ok' => true, 'revision' => $pair['revision']] + ZfsasSchedule::preview($spec, time(), ZfsasSchedule::hostTimezone()));
} catch (Throwable $error) { zfsas_emit_marked_json(['ok' => false, 'error' => $error->getMessage()], 400); }
