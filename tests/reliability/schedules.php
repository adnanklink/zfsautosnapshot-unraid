<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/schedule-spec.php';
function check($value, $message) { if (!$value) { throw new RuntimeException($message); } }
$utc = new DateTimeZone('UTC');
foreach ([420, 18000] as $seconds) {
    $spec = ['version' => 1, 'kind' => 'interval', 'seconds' => $seconds, 'anchor' => 1000];
    check(ZfsasSchedule::occurrence($spec, 1000, $utc, false) === null, 'New interval ran at Save');
    check(ZfsasSchedule::occurrence($spec, 1000, $utc, true) === 1000 + $seconds, 'First interval wrong');
    check(ZfsasSchedule::occurrence($spec, 1000 + 10 * $seconds + 1, $utc, false) === 1000 + 10 * $seconds, 'Catch-up did not coalesce');
    check(ZfsasSchedule::occurrence($spec, 1000 + 10 * $seconds + 1, $utc, true) === 1000 + 11 * $seconds, 'Cadence shifted');
}
$legacy = ['version' => 1, 'kind' => 'cron', 'expression' => '*/7 * * * *'];
$now = strtotime('2026-09-16 00:56:00 UTC');
check(ZfsasSchedule::occurrence($legacy, $now, $utc, true) === strtotime('2026-09-16 01:00:00 UTC'), 'Legacy cron alignment changed');
$zone = new DateTimeZone('America/New_York');
$daily = ['version' => 1, 'kind' => 'daily', 'hour' => 2, 'minute' => 30];
check(ZfsasSchedule::occurrence($daily, strtotime('2026-03-08 06:00:00 UTC'), $zone, true) === strtotime('2026-03-08 07:00:00 UTC'), 'DST gap not caught at first valid time');
$daily['hour'] = 1;
check(ZfsasSchedule::occurrence($daily, strtotime('2026-11-01 05:30:00 UTC'), $zone, true) === strtotime('2026-11-02 06:30:00 UTC'), 'Repeated local time executed twice');
check(ZfsasSchedule::occurrence($daily, strtotime('2026-11-01 06:31:00 UTC'), $zone, false) === strtotime('2026-11-01 05:30:00 UTC'), 'Repeated local time accepted as new occurrence');
$weekly = ['version' => 1, 'kind' => 'weekly', 'day' => 0, 'hour' => 2, 'minute' => 30];
check(ZfsasSchedule::occurrence($weekly, strtotime('2026-03-07 UTC'), $zone, true) === strtotime('2026-03-08 07:00:00 UTC'), 'Weekly DST gap failed');
$cron = ['version' => 1, 'kind' => 'cron', 'expression' => '0 0 29 feb *'];
check(ZfsasSchedule::occurrence($cron, strtotime('2025-01-01 UTC'), $utc, true) === strtotime('2028-02-29 UTC'), 'Leap-day cron failed');
foreach (['* * * *', '60 * * * *', '*/0 * * * *', '* * * * 8', '* * * bad *', '1-0 * * * *'] as $bad) {
    try { ZfsasSchedule::cron($bad); throw new RuntimeException('Invalid cron accepted: ' . $bad); }
    catch (InvalidArgumentException $expected) {}
}
$old = ['SCHEDULE_MODE' => 'minutes', 'SCHEDULE_EVERY_MINUTES' => '7'];
$preserved = ZfsasSchedule::autoSave($old, $old, false, 1000);
$old['SCHEDULE_SPEC'] = json_encode($preserved);
check(ZfsasSchedule::autoSave($old, $old, false, 2000) === $preserved, 'Second unrelated save converted legacy recurrence');
$converted = ZfsasSchedule::autoSave($old, $old, true, 2000);
check($converted['kind'] === 'interval' && $converted['anchor'] === 2000, 'Explicit conversion did not anchor to Save');
$old['SCHEDULE_SPEC'] = json_encode($converted);
check(ZfsasSchedule::autoSave($old, $old, false, 3000) === $converted, 'Unrelated save shifted interval cadence');
echo "PASS: seven-minute/five-hour intervals, Save anchor, coalesced catch-up, legacy alignment, daily/weekly DST gaps and folds, leap-day cron and validation\n";
