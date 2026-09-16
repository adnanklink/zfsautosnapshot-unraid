<?php
require_once __DIR__ . '/schedule-spec.php';

function zfsas_send_schedule_spec(array $config, array $job): array
{
    $specs = json_decode($config['SEND_SCHEDULE_SPECS'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($specs)) { throw new InvalidArgumentException('Invalid send schedule specifications.'); }
    if (isset($specs[$job['id']])) { return ZfsasSchedule::validate($specs[$job['id']]); }
    $seconds = ['6h'=>21600, '12h'=>43200, '1d'=>86400, '7d'=>604800][$job['frequency']] ?? null;
    if (!$seconds) { throw new InvalidArgumentException('Invalid send frequency.'); }
    return ['version'=>1, 'kind'=>'legacy_window', 'seconds'=>$seconds, 'legacy'=>true];
}

function zfsas_send_schedule_save(array $previous, array $job, array $options, bool $isNew, int $now): array
{
    $old = zfsas_send_schedule_spec($previous, $job);
    $convert = ($options['convert'] ?? '') === '1';
    if (!$isNew && !$convert && !empty($old['legacy'])) {
        // Keep the recurrence that was actually active, including its timezone
        // alignment. A different frequency requires explicit conversion.
        return $old;
    }
    $seconds = ['6h'=>21600, '12h'=>43200, '1d'=>86400, '7d'=>604800][$job['frequency']] ?? null;
    if (!$seconds) { throw new InvalidArgumentException('Invalid send frequency.'); }
    if (in_array($job['frequency'], ['6h','12h'], true)) {
        return ['version'=>1, 'kind'=>'interval', 'seconds'=>$seconds,
            'anchor'=>!$convert && !$isNew && $old['kind']==='interval' && $old['seconds']===$seconds ? $old['anchor'] : $now];
    }
    $time = $options['time'] ?? sprintf('%02d:%02d', $old['hour'] ?? 0, $old['minute'] ?? 0);
    if (!is_string($time) || !preg_match('/^(\d{2}):(\d{2})$/D', $time, $match)) { throw new InvalidArgumentException('Choose a valid send start time.'); }
    $spec = ['version'=>1, 'kind'=>$job['frequency']==='1d' ? 'daily' : 'weekly', 'hour'=>(int)$match[1], 'minute'=>(int)$match[2]];
    if ($spec['kind']==='weekly') {
        $day = (string) ($options['day'] ?? $old['day'] ?? '0');
        if (!preg_match('/^[0-6]$/D', $day)) { throw new InvalidArgumentException('Choose a valid send weekday.'); }
        $spec['day'] = (int)$day;
    }
    $comparison = $old; unset($comparison['notBefore']);
    $spec['notBefore'] = !$convert && !$isNew && $comparison === $spec ? ($old['notBefore'] ?? $now) : $now;
    return ZfsasSchedule::validate($spec);
}

function zfsas_send_schedule_specs_save(array $previous, array $submitted, array $options, int $now): string
{
    $oldJobs = zfsas_send_parse_jobs($previous['SEND_JOBS'] ?? '', $errors, $warnings);
    $oldJobs = array_column($oldJobs, null, 'id');
    $jobs = zfsas_send_parse_jobs($submitted['SEND_JOBS'] ?? '', $errors, $warnings);
    $specs = [];
    foreach ($jobs as $job) {
        $id = $job['id']; $base = $previous;
        // Read the old frequency for a legacy job, even if the form changed it.
        if (isset($oldJobs[$id])) {
            $mapping = json_decode($previous['SEND_SCHEDULE_SPECS'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            $mapping[$id] = zfsas_send_schedule_spec($previous, $oldJobs[$id]);
            $base['SEND_SCHEDULE_SPECS'] = json_encode($mapping, JSON_THROW_ON_ERROR);
        }
        $specs[$id] = zfsas_send_schedule_save($base, $job, $options[$id] ?? [], !isset($oldJobs[$id]), $now);
    }
    ksort($specs);
    return json_encode($specs ?: new stdClass(), JSON_THROW_ON_ERROR);
}
