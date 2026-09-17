<?php
/** Single-writer RAM write-ahead log. A flushed record is the acceptance boundary. */
trait ZfsasCoordinatorJournal
{
    private array $publishedEntities = [];
    private int $journalRecords = 0;
    private int $journalBytes = 0;
    private bool $journalFailed = false;
    public int $loadedVersion = 3;
    private const COLLECTIONS = ['commands', 'schedules', 'runs', 'tasks', 'attempts', 'items', 'plans', 'references', 'reservations'];

    private static function emptyState(): array
    {
        return ['version' => 3, 'sequence' => 0] + array_fill_keys(self::COLLECTIONS, []);
    }

    /** Read-only diagnostic view; execution recovery additionally holds owner.lock. */
    public static function readCommitted(string $root): array
    {
        // Diagnostics may race atomic compaction. Retry if either pathname was
        // replaced; execution recovery has the exclusive owner lock instead.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            clearstatcache();
            $before = [@fileinode($root . '/checkpoint.json'), @fileinode($root . '/journal.ndjson')];
            $error = null; $state = null;
            try { $state = self::readJournal($root)[0]; } catch (RuntimeException $caught) { $error = $caught; }
            clearstatcache();
            $after = [@fileinode($root . '/checkpoint.json'), @fileinode($root . '/journal.ndjson')];
            if ($before !== $after) { continue; }
            if ($error) { throw $error; }
            return $state;
        }
        throw new RuntimeException('Coordinator journal changed during inspection; retry the read.');
    }

    private static function decodeEnvelope(string $line): array
    {
        try {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record) || !is_string($record['payload'] ?? null)
                || !is_string($record['sha256'] ?? null)
                || !hash_equals(hash('sha256', $record['payload']), $record['sha256'])) {
                throw new RuntimeException('Coordinator journal is incomplete or corrupt; refusing recovery.');
            }
            $value = json_decode($record['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new RuntimeException('Coordinator journal is corrupt.'); }
            return $value;
        } catch (JsonException $error) {
            throw new RuntimeException('Coordinator journal is corrupt; refusing recovery.', 0, $error);
        }
    }

    private static function readJournal(string $root): array
    {
        $state = self::emptyState(); $version = 3;
        if (is_file($root . '/checkpoint.json')) {
            $state = self::decodeEnvelope((string) file_get_contents($root . '/checkpoint.json'));
            $version = $state['version'] ?? null;
            if (!in_array($version, [1, 2, 3], true)) { throw new RuntimeException('Unsupported coordinator journal version.'); }
            if (!is_int($state['sequence'] ?? null) || $state['sequence'] < 0) { throw new RuntimeException('Coordinator checkpoint sequence is corrupt.'); }
            foreach ($version === 3 ? self::COLLECTIONS : array_slice(self::COLLECTIONS, 0, 5) as $collection) {
                if (!is_array($state[$collection] ?? null)) { throw new RuntimeException('Coordinator checkpoint collection is corrupt.'); }
            }
            $state += self::emptyState();
        }
        $state['version'] = 3;
        $offset = 0; $records = 0; $previous = null;
        $stream = is_file($root . '/journal.ndjson') ? fopen($root . '/journal.ndjson', 'rb') : false;
        if ($stream) {
            try {
                while (($line = fgets($stream)) !== false) {
                    // Only an unfinished final record can be discarded. A complete
                    // malformed record, including the last one, is corruption.
                    if (!str_ends_with($line, "\n")) { break; }
                    $event = self::decodeEnvelope($line);
                    $sequence = $event['sequence'] ?? null;
                    if (($event['version'] ?? null) !== 3 || !is_int($sequence) || $sequence < 1
                        || ($previous !== null && $sequence !== $previous + 1)
                        || !is_array($event['put'] ?? null) || !is_array($event['remove'] ?? null)) {
                        throw new RuntimeException('Coordinator journal sequence or record is corrupt.');
                    }
                    $previous = $sequence;
                    if ($sequence > $state['sequence']) {
                        if ($sequence !== $state['sequence'] + 1) { throw new RuntimeException('Coordinator journal sequence gap; refusing recovery.'); }
                        foreach (['put', 'remove'] as $operation) {
                            foreach ($event[$operation] as $collection => $entries) {
                                if (!in_array($collection, self::COLLECTIONS, true) || !is_array($entries)) { throw new RuntimeException('Coordinator journal collection is corrupt.'); }
                                foreach ($entries as $id => $value) {
                                    if ($operation === 'put') { $state[$collection][$id] = $value; }
                                    else { unset($state[$collection][$value]); }
                                }
                            }
                        }
                        $state['sequence'] = $sequence;
                    }
                    $offset = ftell($stream); $records++;
                }
                if (!feof($stream) && $line === false) { throw new RuntimeException('Cannot read coordinator journal.'); }
            } finally { fclose($stream); }
        }
        return [$state, $version, $offset, $records];
    }

    private function loadJournal(): void
    {
        [$this->state, $this->loadedVersion, $offset, $this->journalRecords] = self::readJournal($this->root);
        $this->journalBytes = $offset;
        $path = $this->root . '/journal.ndjson';
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) !== $offset) {
            $stream = fopen($path, 'c+b');
            if (!$stream) { throw new RuntimeException('Cannot repair interrupted RAM journal append.'); }
            try {
                if (!ftruncate($stream, $offset) || !fflush($stream) || !fsync($stream)) { throw new RuntimeException('Cannot repair interrupted RAM journal append.'); }
            } finally { fclose($stream); }
        }
        if ($this->loadedVersion < 3) {
            foreach ($this->state['runs'] as &$run) {
                if (!empty($run['manual']) && !self::terminal($run['state'])) { $run['upgradeReviewRequired'] = true; }
            }
            unset($run);
        }
        $this->publishedEntities = $this->encodeEntities();
    }

    private function encodeEntities(): array
    {
        $entities = [];
        foreach (self::COLLECTIONS as $collection) {
            $entities[$collection] = [];
            foreach ($this->state[$collection] as $id => $value) {
                // Store encoded values, not PHP references into mutable state.
                $entities[$collection][$id] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        return $entities;
    }

    private static function envelope(array $value): string
    {
        $payload = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode(['sha256' => hash('sha256', $payload), 'payload' => $payload], JSON_THROW_ON_ERROR) . "\n";
    }

    private static function writeJournalBytes($stream, string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Short coordinator journal write.'); }
        }
        if (!fflush($stream) || !fsync($stream)) { throw new RuntimeException('Cannot synchronize RAM journal.'); }
    }

    public function commit(): void
    {
        if ($this->journalFailed) { throw new RuntimeException('Journal publication failed; restart is required.'); }
        try {
            $entities = $this->encodeEntities(); $put = []; $remove = [];
            foreach (self::COLLECTIONS as $collection) {
                foreach ($entities[$collection] as $id => $encoded) {
                    if (($this->publishedEntities[$collection][$id] ?? null) !== $encoded) { $put[$collection][$id] = $this->state[$collection][$id]; }
                }
                $deleted = array_keys(array_diff_key($this->publishedEntities[$collection] ?? [], $entities[$collection]));
                if ($deleted) { $remove[$collection] = $deleted; }
            }
            $sequence = $this->state['sequence'] + 1;
            $bytes = self::envelope(['version' => 3, 'sequence' => $sequence, 'put' => $put, 'remove' => $remove]);
            $stream = fopen($this->root . '/journal.ndjson', 'ab');
            if (!$stream) { throw new RuntimeException('Cannot append RAM journal.'); }
            try { self::writeJournalBytes($stream, $bytes); } finally { fclose($stream); }
            $this->state['sequence'] = $sequence; $this->publishedEntities = $entities;
            $this->updateIndexes($put, $remove);
            $this->journalRecords++; $this->journalBytes += strlen($bytes);
            if (!is_file($this->root . '/checkpoint.json') || $this->loadedVersion !== 3
                || $this->journalRecords >= 1024 || $this->journalBytes >= 8 * 1048576) { $this->checkpoint(); }
        } catch (Throwable $error) { $this->journalFailed = true; throw $error; }
    }

    public function checkpoint(): void
    {
        // Publish the complete checkpoint before replacing the old log. Recovery
        // ignores covered records if interruption occurs between these renames.
        $path = $this->root . '/checkpoint.pending';
        $stream = fopen($path, 'wb');
        if (!$stream) { throw new RuntimeException('Cannot create RAM checkpoint.'); }
        try { self::writeJournalBytes($stream, self::envelope($this->state)); } finally { fclose($stream); }
        if (!rename($path, $this->root . '/checkpoint.json')) { throw new RuntimeException('Cannot publish RAM checkpoint.'); }
        $path = $this->root . '/journal.pending';
        $stream = fopen($path, 'wb');
        if (!$stream) { throw new RuntimeException('Cannot compact RAM journal.'); }
        try { self::writeJournalBytes($stream, ''); } finally { fclose($stream); }
        if (!rename($path, $this->root . '/journal.ndjson')) { throw new RuntimeException('Cannot compact RAM journal.'); }
        $this->journalRecords = 0; $this->journalBytes = 0; $this->loadedVersion = 3;
    }
}
