<?php
require_once __DIR__ . '/coordinator-state.php';

/** Launch gates and process-group recovery. No ZFS/SSH inspection in this loop. */
final class ZfsasCoordinatorExecutor
{
    private ZfsasCoordinatorState $journal;
    private string $root;
    private string $generationFile;
    private string $generation;
    private $command;
    private $outcome;
    private array $processes = [];
    private array $stopping = [];
    private array $exitCodes = [];
    private array $limits;
    private string $lastDataset = '';

    public function __construct(ZfsasCoordinatorState $journal, string $root, string $runtime, callable $command, callable $outcome, array $limits = [])
    {
        $this->journal = $journal; $this->root = $root; $this->command = $command; $this->outcome = $outcome;
        $this->limits = $limits + ['auto' => 1, 'send' => 1, 'prepare' => 16, 'delete' => 1, 'batch' => 16, 'finalize' => 16];
        if (!is_dir($runtime) && !mkdir($runtime, 0770, true)) { throw new RuntimeException('Cannot create coordinator ownership directory.'); }
        $this->generation = bin2hex(random_bytes(24)); $this->generationFile = $runtime . '/generation';
        self::publish($this->generationFile, $this->generation);
        $changed = false;
        foreach ($journal->state['attempts'] as $token => $attempt) {
            if ($attempt['state'] === 'stopped') { continue; }
            $task =& $journal->state['tasks'][$attempt['taskId']];
            if ($task['attempt'] !== $token) { continue; }
            $task['state'] = 'stopping';
            $this->stopping[$token] = ['since' => hrtime(true) / 1e9, 'recovery' => true]; $changed = true;
        }
        if ($journal->loadedVersion < 3) { $journal->requireUpgradeReview(time()); $changed = true; }
        if ($changed) { $journal->commit(); }
    }

    private static function publish(string $path, string $content): void
    {
        if (file_put_contents($path . '.pending', $content) !== strlen($content) || !rename($path . '.pending', $path)) {
            throw new RuntimeException('Cannot publish runtime ownership record.');
        }
    }

    public static function identity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) { return null; }
        $fields = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
        return ['pid' => $pid, 'state' => $fields[0], 'group' => (int) $fields[2], 'start' => (string) $fields[19]];
    }

    /** null means ownership changed, [] means shutdown verified. */
    public static function members(int $pid, string $start): ?array
    {
        $leader = self::identity($pid);
        if ($leader && ($leader['start'] !== $start || $leader['group'] !== $pid)) { return null; }
        $members = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $identity = self::identity((int) basename(dirname($path)));
            if ($identity && $identity['state'] !== 'Z' && $identity['group'] === $pid) { $members[] = $identity; }
        }
        return $members;
    }

    private static function signal(array $identity, int $signal): void
    {
        $current = self::identity($identity['pid']);
        if (!$current || $current['start'] !== $identity['start'] || $current['group'] !== $identity['group']) { return; }
        if (function_exists('posix_kill')) { @posix_kill($identity['pid'], $signal); }
        else { exec('/bin/kill -' . $signal . ' ' . $identity['pid'] . ' 2>/dev/null'); }
    }

    public function workerReport(array $request): array
    {
        return $this->journal->workerReport($request, $this->generation, time());
    }

    public function cancel(string $runId): void
    {
        foreach ($this->journal->cancel($runId, time()) as $token) {
            $this->stopping[$token] ??= ['since' => hrtime(true) / 1e9, 'recovery' => false];
        }
    }

    public function tick(float $now): float
    {
        foreach ($this->journal->activeTaskIds() as $activeTaskId) {
            $token = $this->journal->state['tasks'][$activeTaskId]['attempt'];
            $attempt = $this->journal->state['attempts'][$token];
            if ($attempt['state'] === 'stopped') { continue; }
            $taskId = $attempt['taskId']; $task = $this->journal->state['tasks'][$taskId];
            if ($task['attempt'] !== $token) { continue; }
            $dir = $this->root . '/attempts/' . $token;
            $owner = json_decode((string) @file_get_contents($dir . '/owner.json'), true);
            if ($attempt['pid'] === null && is_array($owner) && ($owner['token'] ?? '') === $token) {
                $identity = self::identity((int) ($owner['pid'] ?? 0));
                if ($identity && $identity['group'] === $identity['pid'] && $identity['start'] === ($owner['start'] ?? '')) {
                    if (isset($this->stopping[$token])) {
                        $this->journal->state['attempts'][$token]['pid'] = $identity['pid'];
                        $this->journal->state['attempts'][$token]['start'] = $identity['start'];
                        $this->journal->commit();
                    } elseif ($this->journal->started($taskId, $token, $identity['pid'], $identity['start'])) {
                        self::publish($dir . '/grant', $token);
                    }
                    $attempt = $this->journal->state['attempts'][$token];
                }
            }
            if (isset($this->processes[$token])) {
                $status = proc_get_status($this->processes[$token]);
                if (!$status['running']) {
                    $this->exitCodes[$token] ??= $status['exitcode'];
                    proc_close($this->processes[$token]); unset($this->processes[$token]);
                }
            }
            if ($attempt['pid'] === null) {
                // A launch without a grant cannot mutate ZFS. Old-generation
                // launchers abort before exec, even if they start after recovery.
                if (isset($this->stopping[$token]) || isset($this->exitCodes[$token])) {
                    $this->journal->stopped($token, time(), $now); unset($this->stopping[$token], $this->exitCodes[$token]);
                }
                continue;
            }
            $members = self::members($attempt['pid'], $attempt['start']);
            if ($members === null) {
                // Keep ownership and reservations; never signal a reused leader.
                $this->journal->state['tasks'][$taskId]['blocked'] = 'recovery_required';
                continue;
            }
            if (isset($this->stopping[$token])) {
                if ($members) {
                    $signal = $now - $this->stopping[$token]['since'] >= 2 ? 9 : 15;
                    foreach ($members as $member) { self::signal($member, $signal); }
                } else {
                    $this->journal->stopped($token, time(), $now); unset($this->stopping[$token], $this->exitCodes[$token]);
                }
            } elseif (isset($this->exitCodes[$token])) {
                if ($members) {
                    // A leader's exit is not completion while pipeline children live.
                    $this->stopping[$token] = ['since' => $now, 'recovery' => true];
                    $this->journal->state['tasks'][$taskId]['state'] = 'stopping'; $this->journal->commit();
                } else {
                    $reported = $this->journal->state['attempts'][$token]['reportedResult'] ?? null;
                    $result = $reported ?? ($this->outcome)($task, $this->exitCodes[$token], $dir);
                    if ($reported !== null && $this->exitCodes[$token] !== 0 && $reported['outcome'] === 'success') {
                        $result = ['outcome' => 'transient_failure', 'message' => 'Worker reported success but exited unsuccessfully.', 'exitCode' => $this->exitCodes[$token]];
                    }
                    $this->journal->result($taskId, $token, $result, $now, time(), true);
                    unset($this->exitCodes[$token]);
                }
            }
        }
        $active = [];
        foreach ($this->journal->activeTaskIds() as $id) {
            $kind = $this->journal->state['tasks'][$id]['kind'];
            $active[$kind] = ($active[$kind] ?? 0) + 1;
        }
        $ready = $this->journal->runnable($now);
        // Rotate datasets at each admission pass while preserving per-dataset order.
        usort($ready, fn($a, $b) => (int) ($this->journal->state['tasks'][$a]['dataset'] === $this->lastDataset) <=> (int) ($this->journal->state['tasks'][$b]['dataset'] === $this->lastDataset));
        foreach ($ready as $taskId) {
            $task = $this->journal->state['tasks'][$taskId]; $kind = $task['kind'];
            if (!in_array($task['state'], ['queued', 'waiting', 'retry_wait'], true)) { continue; }
            if (($active[$kind] ?? 0) >= ($this->limits[$kind] ?? 0)) { continue; }
            $command = ($this->command)($task);
            if ($command === null) { continue; } // Resource/array/configuration admission gate.
            if (isset($command['outcome'])) {
                $this->journal->rejectAdmission($taskId, $command, $now, time());
                continue;
            }
            $token = $this->journal->claim($taskId, $now, time(), $this->generation);
            $dir = $this->root . '/attempts/' . $token;
            if (!mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create attempt launch gate.'); }
            self::publish($dir . '/task-id', $taskId);
            self::publish($dir . '/command.json', json_encode($command, JSON_THROW_ON_ERROR));
            $wrapper = __DIR__ . '/../scripts/coordinator-attempt.sh';
            $detach = __DIR__ . '/../scripts/detach-worker.sh';
            $process = proc_open(['/bin/bash', $detach, 'setsid', '/bin/bash', $wrapper, $dir, $this->generationFile, $this->generation, $token],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
            if (!$process) { $this->journal->stopped($token, time(), $now); continue; }
            $this->processes[$token] = $process; $active[$kind] = ($active[$kind] ?? 0) + 1;
            $this->lastDataset = $task['dataset'];
        }
        // Process checks are only needed while attempts exist; idle scheduling
        // sleeps to its actual deadline instead of scanning inventories.
        return $this->journal->activeTaskIds() ? $now + .1 : $this->journal->nextDeadline($now);
    }
}
