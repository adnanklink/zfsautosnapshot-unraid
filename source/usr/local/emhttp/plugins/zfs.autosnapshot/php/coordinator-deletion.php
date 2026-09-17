<?php
/** Owns deletion admission and compatibility projections; workers execute once. */
final class ZfsasCoordinatorDeletion
{
    private ZfsasCoordinatorState $journal;
    private string $root;
    private array $active = [];
    private bool $dirty = true;
    private float $nextImport = 0;
    private const FIELDS = ['JOB_ID','REQUESTED_EPOCH','QUEUE_SORT','DATASET','SNAPSHOT','SNAPSHOT_NAME','SNAPSHOT_EPOCH','SNAPSHOT_GUID','SNAPSHOT_CREATETXG','DELETE_POOL','ESTIMATED_RECLAIM_BYTES','SEND_PROTECTED','DELETE_SCOPE','SEND_SCHEDULE_JOB_ID','SEND_CONFIG_HASH'];

    public function __construct(ZfsasCoordinatorState $journal, string $root)
    {
        $this->journal = $journal; $this->root = $root;
        // Preserve the old display before replacing it with a projection. It is
        // evidence for review, never authority to recreate deletion work.
        $oldState = zfsas_ops_delete_queue_state_path();
        if (is_file($oldState)) {
            $text = (string) file_get_contents($oldState);
            if ($text !== '' && !str_starts_with($text, "COORDINATOR_PROTOCOL=3\n")) {
                self::publish($root . '/legacy-deletion-review/' . hash('sha256', $text) . '.state', $text);
            }
        }
        foreach ($journal->state['tasks'] as $id => $task) {
            if ($task['kind'] === 'delete' && isset($task['parameters']['deleteJob'])) { $this->changed($id); }
        }
    }

    private static function publish(string $path, string $text): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0770, true)) { throw new RuntimeException('Cannot create deletion runtime storage.'); }
        if (is_file($path) && file_get_contents($path) === $text) { return; }
        $tmp = tempnam(dirname($path), '.delete-');
        if (!$tmp) { throw new RuntimeException('Cannot stage deletion projection.'); }
        try {
            if (file_put_contents($tmp, $text) !== strlen($text)) { throw new RuntimeException('Cannot write deletion projection.'); }
            chmod($tmp, 0660); zfsas_ops_apply_owner($tmp);
            if (!rename($tmp, $path)) { throw new RuntimeException('Cannot publish deletion projection.'); }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
    }

    private function quarantine(string $line): void
    {
        $path = $this->root . '/deletion-review-required.log';
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) >= 1048576) { rename($path, $path . '.previous'); }
        if (file_put_contents($path, substr($line, 0, 8192) . "\n", FILE_APPEND) === false) { throw new RuntimeException('Cannot retain rejected deletion evidence.'); }
    }

    private function accept(string $line): ?string
    {
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (array_shift($parts) !== 'ENQUEUE3' || count($parts) !== count(self::FIELDS)) { $this->quarantine($line); return null; }
        $job = array_combine(self::FIELDS, $parts);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,160}$/D', $job['JOB_ID'])
            || !zfsas_sm_is_valid_dataset_name($job['DATASET'])
            || $job['SNAPSHOT'] !== $job['DATASET'] . '@' . $job['SNAPSHOT_NAME']
            || !zfsas_sm_is_valid_snapshot_name($job['SNAPSHOT_NAME']) || !ctype_digit($job['SNAPSHOT_GUID'])
            || !in_array($job['DELETE_SCOPE'], ['snapshot','destination_checkpoint'], true)) {
            $this->quarantine($line); return null;
        }
        $owner = '';
        if (preg_match('/^sm-([a-f0-9]{32})-/', $job['JOB_ID'], $match)) {
            $receipt = $this->journal->state['commands']['batch-' . $match[1]] ?? null;
            $run = $receipt ? ($this->journal->state['runs'][$receipt['runId']] ?? null) : null;
            if (!$run || ZfsasCoordinatorState::terminal($run['state']) || $run['state'] === 'canceling' || !empty($run['upgradeReviewRequired'])) {
                $this->quarantine($line); return null;
            }
            $owner = $run['id'];
        } elseif (!preg_match('/^[a-f0-9]{64}$/D', $job['SEND_CONFIG_HASH'])) {
            $this->quarantine($line); return null;
        }
        $command = 'delete-' . hash('sha256', $job['JOB_ID']);
        if (isset($this->journal->state['commands'][$command])) {
            $receipt = $this->journal->state['commands'][$command];
            $existing = $this->journal->state['tasks'][$receipt['runId'] . ':snapshot']['parameters']['deleteJob'] ?? null;
            if ($existing) {
                foreach (['DATASET','SNAPSHOT','SNAPSHOT_GUID','DELETE_SCOPE','SEND_SCHEDULE_JOB_ID','SEND_CONFIG_HASH'] as $field) {
                    if ($existing[$field] !== $job[$field]) { $this->quarantine($line); return null; }
                }
            }
            return $receipt['runId'];
        }
        $receipt = $this->journal->submit($command, ['manual'=>false, 'tasks'=>['snapshot'=>[
            'kind'=>'delete', 'dataset'=>$job['DATASET'], 'parameters'=>['deleteJob'=>$job, 'ownerRunId'=>$owner]]]], time());
        $this->changed($receipt['runId'] . ':snapshot');
        return $receipt['runId'];
    }

    public function request(): array
    {
        $this->nextImport = 0;
        return ['queued'=>true];
    }

    public function tick(float $now): float
    {
        if ($now >= $this->nextImport) { $more = $this->import(); $this->nextImport = $now + ($more ? .05 : 30); }
        if ($this->dirty) { $this->project(); }
        return $this->nextImport;
    }

    private function import(): bool
    {
        $inbox = zfsas_ops_delete_queue_inbox_path();
        if (!is_dir(dirname($inbox))) { return false; }
        $lock = fopen(zfsas_ops_delete_queue_inbox_lock_path(), 'c');
        if (!$lock) { throw new RuntimeException('Cannot lock deletion submissions.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return true; }
            $spool = $this->root . '/deletion-inbox'; $cursorPath = $spool . '.cursor';
            if (!is_file($spool)) {
                clearstatcache(true, $inbox);
                if (!is_file($inbox) || filesize($inbox) === 0) { return false; }
                // Cursor removal precedes rename: a new spool always starts at zero.
                if (is_file($cursorPath)) { unlink($cursorPath); }
                if (!rename($inbox, $spool)) { throw new RuntimeException('Cannot claim deletion submissions.'); }
            }
            $cursor = is_file($cursorPath) ? (int) file_get_contents($cursorPath) : 0;
            $stream = fopen($spool, 'rb');
            if (!$stream || fseek($stream, $cursor) !== 0) { throw new RuntimeException('Cannot read deletion submissions.'); }
            try {
                for ($count = 0; $count < 50 && ($line = fgets($stream)) !== false; $count++) {
                    if (strlen($line) > 8192 || !str_ends_with($line, "\n")) { $this->quarantine($line); }
                    else { $this->accept($line); }
                    // Publication precedes advancing the cursor. Replay is idempotent.
                    self::publish($cursorPath, (string) ftell($stream));
                }
                $done = feof($stream);
            } finally { fclose($stream); }
            if ($done) { unlink($spool); unlink($cursorPath); }
            return true; // Recheck once for submissions appended during draining.
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function command(array $task): array
    {
        if (!isset($task['parameters']['deleteJob'])) {
            return ['outcome'=>'validation_failure', 'recoveryRequired'=>true,
                'message'=>'Legacy deletion execution authority requires a fresh plan.'];
        }
        $ownerId = $task['parameters']['ownerRunId'] ?? '';
        if ($ownerId !== '') {
            $owner = $this->journal->state['runs'][$ownerId] ?? null;
            if (!$owner || ZfsasCoordinatorState::terminal($owner['state']) || $owner['state'] === 'canceling'
                || !empty($owner['upgradeReviewRequired'])
                || is_file(zfsas_ops_control_path('cancelled', $ownerId))) {
                return ['outcome'=>'validation_failure', 'message'=>'Deletion owner no longer authorizes execution; review the unfinished selection again.'];
            }
        }
        $job = $task['parameters']['deleteJob'];
        $path = $this->root . '/attempt-inputs/' . hash('sha256', $task['id']) . '.job';
        $text = "JOB_TYPE=\"delete\"\n";
        foreach ($job as $key => $value) { $text .= $key . '="' . str_replace(['\\','"'], ['\\\\','\\"'], $value) . '"' . "\n"; }
        self::publish($path, $text);
        return ['/bin/bash', __DIR__ . '/../scripts/coordinator-delete-attempt.sh', $path];
    }

    public function changed(string $id): void
    {
        $task = $this->journal->state['tasks'][$id];
        if ($task['kind'] !== 'delete' || !isset($task['parameters']['deleteJob'])) { return; }
        if (ZfsasCoordinatorState::terminal($task['state'])) {
            unset($this->active[$id]);
            $result = $task['result'] ?? [];
            $state = $task['state'] === 'complete' ? ($result['itemState'] ?? 'completed') : 'failed';
            if (!in_array($state, ['completed','skipped','failed'], true)) { $state = 'failed'; }
            self::publish(zfsas_ops_status_dir() . '/delete-results/' . $task['parameters']['deleteJob']['JOB_ID'] . '.result',
                $state . "\t" . str_replace(["\t","\r","\n"], ' ', $result['message'] ?? 'Deletion did not complete.') . "\n");
        } else { $this->active[$id] = true; }
        $this->dirty = true;
    }

    private function project(): void
    {
        $rows = []; $counts = ['queued'=>0,'running'=>0,'retry_wait'=>0];
        foreach (array_keys($this->active) as $id) {
            $task = $this->journal->state['tasks'][$id]; $job = $task['parameters']['deleteJob'];
            $state = in_array($task['state'], ['running','launching','stopping'], true) ? 'running' : ($task['state'] === 'retry_wait' ? 'retry_wait' : 'queued');
            $counts[$state]++;
            $fields = ['JOB', $job['JOB_ID'], $state, (string) ($task['retryAt'] ?? 0)];
            foreach (array_slice(self::FIELDS, 1, 13) as $field) { $fields[] = $job[$field]; }
            $fields[] = (string) ($this->journal->state['attempts'][$task['attempt'] ?? '']['pid'] ?? '');
            $fields[] = $job['SEND_CONFIG_HASH'];
            $rows[] = implode("\t", $fields);
        }
        $text = "COORDINATOR_PROTOCOL=3\n" . 'PENDING_COUNT=' . ($counts['queued'] + $counts['retry_wait']) . "\nRUNNING_COUNT=" . $counts['running'] . "\nRETRY_WAIT_COUNT=" . $counts['retry_wait'] . "\nFAILED_COUNT=0\n";
        self::publish(zfsas_ops_delete_queue_state_path(), $text . ($rows ? implode("\n", $rows) . "\n" : ''));
        $this->dirty = false;
    }
}
