<?php
function zfsas_sm_cleanup_plan(array $rows, $mode, array $config, $now = null, $managedOnly = true)
{
    $now = $now ?? time();
    $rows = zfsas_sm_filter_rows($rows, ['sort' => 'creation', 'direction' => 'desc'], $now);
    $all = (int) $config['KEEP_ALL_FOR_DAYS'] * 86400;
    $daily = (int) $config['KEEP_DAILY_UNTIL_DAYS'] * 86400;
    $weekly = (int) $config['KEEP_WEEKLY_UNTIL_DAYS'] * 86400;
    $newest = null; $anchor = null; $days = []; $weeks = []; $plan = [];
    foreach ($rows as $row) {
        $reason = ''; $candidate = false;
        $inScope = !$managedOnly || $row['origin'] === 'auto';
        if (!$inScope) { $reason = 'Outside Auto Snapshot prefix'; $anchor = null; }
        elseif ($newest === null) { $newest = $row['identity']; $reason = 'Newest snapshot in cleanup scope'; $anchor = $row['writtenBytes'] === 0 ? $row['identity'] : null; }
        elseif ($mode === 'zero_change') {
            if ($row['writtenBytes'] === 0 && $anchor !== null) { $candidate = true; $reason = 'Older duplicate in a zero-written run'; }
            elseif ($row['writtenBytes'] === 0) { $anchor = $row['identity']; $reason = 'Required zero-written run anchor'; }
            else { $anchor = null; $reason = $row['writtenBytes'] === null ? 'Unknown Written value' : 'Written is not zero'; }
        } else {
            $age = $now - $row['createdEpoch'];
            if ($age > $weekly) { $candidate = true; $reason = 'Older than configured weekly retention window'; }
            elseif ($age > $daily) {
                $key = zfsas_sm_retention_week($row['createdEpoch']);
                $candidate = isset($weeks[$key]); $weeks[$key] = true;
                $reason = $candidate ? 'Duplicate in configured weekly retention window' : 'Required weekly retention anchor';
            } elseif ($age > $all) {
                $key = date('Y-m-d', $row['createdEpoch']);
                $candidate = isset($days[$key]); $days[$key] = true;
                $reason = $candidate ? 'Duplicate in configured daily retention window' : 'Required daily retention anchor';
            } else { $reason = 'Within configured keep-all window'; }
        }
        $protection = zfsas_sm_exclusion('delete', $row);
        if ($protection !== '') { $candidate = false; $reason = $protection . ($reason !== '' ? '; ' . $reason : ''); }
        if (empty($row['metadataComplete'])) { $anchor = null; }
        $plan[] = ['snapshot' => $row['snapshot'], 'guid' => $row['guid'], 'identity' => $row['identity'],
            'candidate' => $candidate, 'reason' => $reason, 'state' => $candidate ? 'queued' : 'skipped'];
    }
    return $plan;
}

function zfsas_sm_retention_week($epoch)
{
    $year = date('Y', $epoch);
    $jan = strtotime($year . '-01-01 00:00:00');
    $firstMonday = (8 - (int) date('N', $jan)) % 7;
    $day = (int) date('z', $epoch);
    $week = $day < $firstMonday ? 0 : (int) floor(($day - $firstMonday) / 7) + 1;
    return $year . '-' . sprintf('%02d', $week);
}
