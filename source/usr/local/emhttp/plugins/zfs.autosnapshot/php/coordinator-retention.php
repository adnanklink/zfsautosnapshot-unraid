<?php
/** Remove only artifacts no longer referenced by the committed journal. */
function zfsas_coordinator_prune_artifacts(ZfsasCoordinatorState $journal, string $root, string $batches, int $now): void
{
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) { $removeTree($entry->getPathname()); }
        }
        @rmdir($path);
    };
    foreach (glob($root . '/attempts/*') ?: [] as $path) {
        $token = basename($path);
        if (preg_match('/^[a-f0-9]{48}$/D', $token) && !isset($journal->state['attempts'][$token])) { $removeTree($path); }
    }
    $revisions = []; $activeBatches = [];
    foreach ($journal->state['tasks'] as $task) {
        if (!empty($task['parameters']['revision'])) { $revisions[$task['parameters']['revision']] = true; }
        if ($task['kind'] === 'batch' && !ZfsasCoordinatorState::terminal($journal->state['runs'][$task['runId']]['state'])) {
            $activeBatches[$task['parameters']['token']] = true;
        }
    }
    foreach (glob($root . '/config/*') ?: [] as $path) {
        $revision = basename($path);
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) && !isset($revisions[$revision])) { $removeTree($path); }
    }
    $terminal = [];
    foreach (glob($batches . '/*.json') ?: [] as $path) {
        $token = basename($path, '.json');
        if (isset($activeBatches[$token]) || is_link($path)) { continue; }
        $batch = json_decode((string) @file_get_contents($path), true);
        if (!$batch || !in_array($batch['state'] ?? '', ['complete', 'canceled', 'draft', 'review'], true)) { continue; }
        $terminal[$path] = (int) ($batch['created'] ?? $now);
    }
    arsort($terminal); $count = 0;
    foreach ($terminal as $path => $created) {
        if (++$count <= 1000 && $created >= $now - 30 * 86400) { continue; }
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock) { continue; }
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            // Re-read under the shared publication lock: a reviewed manifest may
            // have been approved since the retention inventory was collected.
            $batch = json_decode((string) @file_get_contents($path), true);
            if ($batch && in_array($batch['state'] ?? '', ['complete', 'canceled', 'draft', 'review'], true)) { @unlink($path); }
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        // Keep the lock inode: unlinking it can create two owners for one token.
    }
}
