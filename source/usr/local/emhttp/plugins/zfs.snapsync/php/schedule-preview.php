<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/schedule-spec.php';
try {
    $pair = zfsas_config_read_pair('/boot/config/plugins/zfs.snapsync');
    if (($_GET['kind'] ?? '') === 'send') {
        $id = $_GET['job_id'] ?? '';
        $jobs = zfsas_send_parse_jobs($pair['send']['SEND_JOBS'], $errors, $warnings);
        $found = null;
        foreach ($jobs as $job) { if ($job['id'] === $id) { $found = $job; break; } }
        $job = $found ?? ['id'=>'000000000000', 'frequency'=>'6h'];
        $base = $pair['send'];
        if ($found) {
            $mapping = json_decode($base['SEND_SCHEDULE_SPECS'], true, 64, JSON_THROW_ON_ERROR);
            $mapping[$id] = zfsas_send_schedule_spec($base, $found);
            $base['SEND_SCHEDULE_SPECS'] = json_encode($mapping, JSON_THROW_ON_ERROR);
        }
        $job['frequency'] = $_GET['frequency'] ?? $job['frequency'];
        $spec = zfsas_send_schedule_save($base, $job, ['convert'=>$_GET['convert'] ?? '', 'time'=>$_GET['time'] ?? '00:00', 'day'=>$_GET['day'] ?? '0'], !$found, time());
    } elseif (isset($_GET['spec'])) {
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
