<?php
/** Shared occurrence calculation for coordinator and settings previews. */
final class ZfsasSchedule
{
    public static function validate(array $spec): array
    {
        if (($spec['version'] ?? null) !== 1) { throw new InvalidArgumentException('Unsupported schedule version.'); }
        if (isset($spec['notBefore']) && !is_int($spec['notBefore'])) { throw new InvalidArgumentException('Invalid schedule activation time.'); }
        $kind = $spec['kind'] ?? '';
        if (!in_array($kind, ['disabled', 'interval', 'daily', 'weekly', 'cron', 'legacy_interval', 'legacy_window'], true)) { throw new InvalidArgumentException('Invalid schedule kind.'); }
        if ($kind === 'legacy_window' && !in_array($spec['seconds'] ?? null, [21600,43200,86400,604800], true)) { throw new InvalidArgumentException('Invalid legacy send interval.'); }
        if (in_array($kind, ['interval', 'legacy_interval'], true)) {
            if (!is_int($spec['seconds'] ?? null) || $spec['seconds'] < 60 || $spec['seconds'] > 366 * 86400
                || !is_int($spec['anchor'] ?? null)) { throw new InvalidArgumentException('Interval requires integer seconds and a Save anchor.'); }
        }
        if (in_array($kind, ['daily', 'weekly'], true)) {
            if (!is_int($spec['hour'] ?? null) || !is_int($spec['minute'] ?? null) || $spec['hour'] < 0 || $spec['hour'] > 23 || $spec['minute'] < 0 || $spec['minute'] > 59) {
                throw new InvalidArgumentException('Invalid calendar time.');
            }
            if ($kind === 'weekly' && (!is_int($spec['day'] ?? null) || $spec['day'] < 0 || $spec['day'] > 6)) { throw new InvalidArgumentException('Invalid weekday.'); }
        }
        if ($kind === 'cron') { self::cron((string) ($spec['expression'] ?? '')); }
        return $spec;
    }

    public static function cron(string $expression): array
    {
        $fields = preg_split('/\s+/', trim($expression));
        if (count($fields) !== 5) { throw new InvalidArgumentException('Cron must have exactly five fields.'); }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $names = [[], [], [], array_flip(['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']), array_flip(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])];
        $sets = [];
        foreach ($fields as $index => $field) {
            $field = strtolower($field);
            if ($names[$index]) {
                $field = preg_replace_callback('/[a-z]+/', static function ($match) use ($names, $index) {
                    if (!isset($names[$index][$match[0]])) { throw new InvalidArgumentException('Invalid cron name.'); }
                    return (string) ($names[$index][$match[0]] + ($index === 3 ? 1 : 0));
                }, $field);
            }
            [$min, $max] = $ranges[$index]; $set = [];
            foreach (explode(',', $field) as $part) {
                if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/D', $part, $match)) { throw new InvalidArgumentException('Invalid cron field.'); }
                $step = isset($match[2]) ? (int) $match[2] : 1;
                if ($step < 1 || $step > $max + 1) { throw new InvalidArgumentException('Invalid cron step.'); }
                if ($match[1] === '*') { $lo = $min; $hi = $max; }
                else {
                    $ends = explode('-', $match[1]); $lo = (int) $ends[0];
                    $hi = isset($ends[1]) ? (int) $ends[1] : (isset($match[2]) ? $max : $lo);
                }
                if ($lo < $min || $hi > $max || $lo > $hi) { throw new InvalidArgumentException('Cron value out of range.'); }
                for ($value = $lo; $value <= $hi; $value += $step) { $set[$index === 4 && $value === 7 ? 0 : $value] = true; }
            }
            $sets[] = $set;
        }
        return ['fields' => $sets, 'domWildcard' => str_starts_with($fields[2], '*'), 'dowWildcard' => str_starts_with($fields[4], '*')];
    }

    private static function dayMatches(DateTimeImmutable $day, array $cron): bool
    {
        $f = $cron['fields'];
        if (!isset($f[3][(int) $day->format('n')])) { return false; }
        $dom = isset($f[2][(int) $day->format('j')]); $dow = isset($f[4][(int) $day->format('w')]);
        return (!$cron['domWildcard'] && !$cron['dowWildcard']) ? ($dom || $dow) : ($dom && $dow);
    }

    /** Map a local minute to its first occurrence, or first valid minute after a gap. */
    private static function localMinute(DateTimeImmutable $day, int $minute, DateTimeZone $zone): int
    {
        $local = $day->format('Y-m-d') . sprintf(' %02d:%02d:00', intdiv($minute, 60), $minute % 60);
        $naive = (new DateTimeImmutable($local, new DateTimeZone('UTC')))->getTimestamp();
        $transitions = $zone->getTransitions($naive - 2 * 86400, $naive + 2 * 86400);
        if ($transitions === false) { return (new DateTimeImmutable($local, $zone))->getTimestamp(); }
        $candidates = []; $lastOffset = null;
        foreach ($transitions as $transition) {
            $offset = $transition['offset']; $epoch = $naive - $offset;
            if ((new DateTimeImmutable('@' . $epoch))->setTimezone($zone)->format('Y-m-d H:i:s') === $local) { $candidates[] = $epoch; }
            if ($lastOffset !== null && $offset > $lastOffset && $naive >= $transition['ts'] + $lastOffset && $naive < $transition['ts'] + $offset) {
                $candidates[] = $transition['ts'];
            }
            $lastOffset = $offset;
        }
        if (!$candidates) { throw new RuntimeException('Cannot map scheduled local time.'); }
        return min($candidates);
    }

    public static function occurrence(array $spec, int $time, DateTimeZone $zone, bool $next): ?int
    {
        self::validate($spec);
        if ($spec['kind'] === 'disabled') { return null; }
        if ($spec['kind'] === 'legacy_window') {
            $window = static function (int $epoch) use ($zone, $spec): int {
                $offset = $zone->getOffset(new DateTimeImmutable('@' . $epoch));
                $local = $epoch + $offset;
                return $local - ($local % $spec['seconds']) - $offset;
            };
            $accepted = $window($time);
            if (!$next) { return $accepted; }
            // Legacy recurrence changes at local window boundaries, including
            // timezone transitions. Preview the first minute the old scheduler
            // would observe a strictly newer window.
            for ($candidate = $time - ($time % 60) + 60, $end = $time + $spec['seconds'] + 86400; $candidate <= $end; $candidate += 60) {
                if ($window($candidate) > $accepted) { return $candidate; }
            }
            return null;
        }
        if (in_array($spec['kind'], ['interval', 'legacy_interval'], true)) {
            $anchor = $spec['anchor']; $seconds = $spec['seconds'];
            $index = (int) floor(($time - $anchor) / $seconds) + ($next ? 1 : 0);
            $first = $spec['kind'] === 'interval' ? 1 : 0;
            if (!$next && $index < $first) { return null; }
            return $anchor + max($first, $index) * $seconds;
        }
        $day = (new DateTimeImmutable('@' . $time))->setTimezone($zone)->setTime(0, 0);
        $cron = $spec['kind'] === 'cron' ? self::cron($spec['expression']) : null;
        // Gregorian calendars repeat within this bounded horizon for supported
        // five-field cron expressions, including leap-day schedules.
        for ($offset = 0; $offset < 8 * 366; $offset++) {
            $minutes = [];
            if ($cron) {
                if (self::dayMatches($day, $cron)) {
                    foreach (array_keys($cron['fields'][1]) as $hour) {
                        foreach (array_keys($cron['fields'][0]) as $minute) { $minutes[] = 60 * $hour + $minute; }
                    }
                }
            } elseif ($spec['kind'] !== 'weekly' || (int) $day->format('w') === $spec['day']) {
                $minutes[] = 60 * $spec['hour'] + $spec['minute'];
            }
            $epochs = [];
            foreach ($minutes as $minute) { $epochs[self::localMinute($day, $minute, $zone)] = true; }
            $epochs = array_keys($epochs); sort($epochs, SORT_NUMERIC);
            if (!$next) { $epochs = array_reverse($epochs); }
            foreach ($epochs as $epoch) { if ($epoch < ($spec['notBefore'] ?? PHP_INT_MIN)) { continue; } if ($next ? $epoch > $time : $epoch <= $time) { return $epoch; } }
            if (!$next && $day->getTimestamp() < ($spec['notBefore'] ?? PHP_INT_MIN)) { return null; }
            $day = $day->modify($next ? '+1 day' : '-1 day');
        }
        return null;
    }

    public static function autoConfig(array $config): array
    {
        if (!empty($config['SCHEDULE_SPEC'])) {
            return self::validate(json_decode($config['SCHEDULE_SPEC'], true, 32, JSON_THROW_ON_ERROR));
        }
        $mode = strtolower($config['SCHEDULE_MODE'] ?? 'disabled');
        $expression = match ($mode) {
            'minutes' => '*/' . ($config['SCHEDULE_EVERY_MINUTES'] ?? '15') . ' * * * *',
            'hourly' => '0 */' . ($config['SCHEDULE_EVERY_HOURS'] ?? '1') . ' * * *',
            'daily' => ($config['SCHEDULE_DAILY_MINUTE'] ?? '0') . ' ' . ($config['SCHEDULE_DAILY_HOUR'] ?? '3') . ' * * *',
            'weekly' => ($config['SCHEDULE_WEEKLY_MINUTE'] ?? '0') . ' ' . ($config['SCHEDULE_WEEKLY_HOUR'] ?? '3') . ' * * ' . ($config['SCHEDULE_WEEKLY_DAY'] ?? '0'),
            'custom' => $config['CUSTOM_CRON_SCHEDULE'] ?? '',
            '' => $config['CRON_SCHEDULE'] ?? '',
            'disabled' => '',
            default => throw new InvalidArgumentException('Invalid Auto Snapshot schedule mode.')
        };
        return self::validate($expression === '' ? ['version' => 1, 'kind' => 'disabled']
            : ['version' => 1, 'kind' => 'cron', 'expression' => $expression, 'legacy' => true]);
    }

    public static function autoSave(array $previous, array $submitted, bool $convert, int $now): array
    {
        $existing = self::autoConfig($previous);
        $mode = $submitted['SCHEDULE_MODE'] ?? 'disabled';
        $isNew = ($previous['SCHEDULE_MODE'] ?? 'disabled') === 'disabled' && $mode !== 'disabled';
        if (!$convert && !$isNew && (empty($previous['SCHEDULE_SPEC']) || !empty($existing['legacy']))) {
            // Legacy recurrence remains legacy even when unrelated settings save.
            unset($submitted['SCHEDULE_SPEC']);
            return self::autoConfig($submitted);
        }
        $spec = ['version' => 1, 'kind' => 'disabled'];
        if (in_array($mode, ['minutes', 'hourly'], true)) {
            $seconds = $mode === 'minutes' ? 60 * (int) $submitted['SCHEDULE_EVERY_MINUTES'] : 3600 * (int) $submitted['SCHEDULE_EVERY_HOURS'];
            $anchor = !$convert && ($existing['kind'] ?? '') === 'interval' && ($existing['seconds'] ?? 0) === $seconds ? $existing['anchor'] : $now;
            $spec = ['version' => 1, 'kind' => 'interval', 'seconds' => $seconds, 'anchor' => $anchor];
        } elseif (in_array($mode, ['daily', 'weekly'], true)) {
            $prefix = $mode === 'daily' ? 'SCHEDULE_DAILY_' : 'SCHEDULE_WEEKLY_';
            $spec = ['version' => 1, 'kind' => $mode, 'hour' => (int) $submitted[$prefix . 'HOUR'], 'minute' => (int) $submitted[$prefix . 'MINUTE']];
            if ($mode === 'weekly') { $spec['day'] = (int) $submitted['SCHEDULE_WEEKLY_DAY']; }
        } elseif ($mode === 'custom') {
            $spec = ['version' => 1, 'kind' => 'cron', 'expression' => $submitted['CUSTOM_CRON_SCHEDULE']];
        }
        return self::validate($spec);
    }

    public static function hostTimezone(): DateTimeZone
    {
        $candidates = [getenv('TZ') ?: '', trim((string) @file_get_contents('/etc/timezone'))];
        $link = @readlink('/etc/localtime');
        if ($link && str_contains($link, '/zoneinfo/')) { $candidates[] = explode('/zoneinfo/', $link, 2)[1]; }
        $identity = (string) @file_get_contents('/boot/config/ident.cfg');
        if (preg_match('/^TIMEZONE=["\']?([^"\'\r\n]+)/m', $identity, $match)) { $candidates[] = $match[1]; }
        $candidates[] = date_default_timezone_get();
        foreach ($candidates as $candidate) {
            if ($candidate === '') { continue; }
            try { return new DateTimeZone($candidate); } catch (Exception $error) { continue; }
        }
        return new DateTimeZone('UTC');
    }

    public static function preview(array $spec, int $now, DateTimeZone $zone): array
    {
        $next = self::occurrence($spec, $now, $zone, true);
        return ['spec' => $spec, 'timezone' => $zone->getName(), 'nextScheduledTime' => $next,
            'nextScheduledText' => $next === null ? null : (new DateTimeImmutable('@' . $next))->setTimezone($zone)->format('Y-m-d H:i:s T')];
    }
}
