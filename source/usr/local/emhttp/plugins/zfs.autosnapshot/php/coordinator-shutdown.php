<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-executor.php';
$root = '/tmp/zfs-autosnapshot-coordinator';
if (!is_file($root . '/checkpoint.json')) { exit(0); }
$journal = new ZfsasCoordinatorState($root);
$attempts = array_filter($journal->state['attempts'], static fn($attempt) => $attempt['state'] !== 'stopped' && $attempt['pid'] !== null);
for ($round = 0; $round < 120; $round++) {
    $active = false;
    foreach ($attempts as $attempt) {
        $members = ZfsasCoordinatorExecutor::members($attempt['pid'], $attempt['start']);
        if ($members === null) { fwrite(STDERR, "Coordinator worker ownership changed; shutdown cannot be verified.\n"); exit(1); }
        foreach ($members as $member) {
            $current = ZfsasCoordinatorExecutor::identity($member['pid']);
            if (!$current || $current['start'] !== $member['start']) { continue; }
            $active = true;
            exec('/bin/kill -' . ($round < 100 ? 15 : 9) . ' ' . $member['pid'] . ' 2>/dev/null');
        }
    }
    if (!$active) { exit(0); }
    usleep(100000);
}
fwrite(STDERR, "Coordinator workers remain active; ownership preserved.\n"); exit(1);
