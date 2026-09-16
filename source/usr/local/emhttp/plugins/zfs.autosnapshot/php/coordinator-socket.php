<?php
require_once __DIR__ . '/coordinator-state.php';

/** Nonblocking local RPC transport. ZFS/SSH work belongs in execution adapters. */
final class ZfsasCoordinatorSocket
{
    private $server;
    private array $clients = [];
    private string $path;
    private $handler;
    private $tick;
    private bool $running = true;

    public function __construct(string $path, callable $handler, callable $tick)
    {
        if (!str_starts_with($path, '/var/run/') && !str_starts_with($path, '/tmp/')) {
            throw new InvalidArgumentException('Coordinator socket must use RAM.');
        }
        $parent = dirname($path);
        if (is_link($parent) || is_link($path)) { throw new RuntimeException('Unsafe coordinator socket path.'); }
        if (!is_dir($parent) && !mkdir($parent, 0770, true)) { throw new RuntimeException('Cannot create socket directory.'); }
        // The caller must already own the permanent coordinator lock. A socket
        // pathname can outlive a crashed process; its inode is not ownership.
        if (file_exists($path) && !unlink($path)) { throw new RuntimeException('Cannot replace stale coordinator socket.'); }
        $this->server = stream_socket_server('unix://' . $path, $code, $error);
        if (!$this->server) { throw new RuntimeException('Cannot listen on coordinator socket: ' . $error); }
        chmod($path, 0660);
        @chown($parent, 'nobody'); @chgrp($parent, 'users');
        @chown($path, 'nobody'); @chgrp($path, 'users');
        stream_set_blocking($this->server, false);
        $this->path = $path; $this->handler = $handler; $this->tick = $tick;
    }

    public function stop(): void { $this->running = false; }

    public function serve(): void
    {
        $nextTick = 0.0;
        while ($this->running) {
            $now = hrtime(true) / 1e9;
            if ($now >= $nextTick) {
                // The executor returns its next monotonic deadline. Recovery is
                // checked at least every 30 seconds, without directory polling.
                $nextTick = min($now + 30, max($now + 0.01, (float) ($this->tick)($now)));
            }
            $read = [$this->server]; $write = []; $deadline = $nextTick;
            foreach ($this->clients as $client) {
                $deadline = min($deadline, $client['deadline']);
                if ($client['output'] === null) { $read[] = $client['stream']; }
                else { $write[] = $client['stream']; }
            }
            $wait = max(0, $deadline - hrtime(true) / 1e9);
            $except = null;
            $selected = @stream_select($read, $write, $except, (int) $wait, (int) (($wait - (int) $wait) * 1e6));
            if ($selected === false) { continue; } // Interrupted by signal.
            foreach ($read as $stream) {
                if ($stream === $this->server) {
                    while (($accepted = @stream_socket_accept($this->server, 0)) !== false) {
                        if (count($this->clients) >= 64) { fclose($accepted); continue; }
                        stream_set_blocking($accepted, false);
                        $this->clients[(int) $accepted] = ['stream' => $accepted, 'input' => '', 'output' => null,
                            'deadline' => hrtime(true) / 1e9 + 5];
                    }
                    continue;
                }
                $id = (int) $stream;
                $chunk = @fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) { $this->close($id); continue; }
                $this->clients[$id]['input'] .= $chunk;
                if (strlen($this->clients[$id]['input']) > 1048576) { $this->close($id); continue; }
                $lineEnd = strpos($this->clients[$id]['input'], "\n");
                if ($lineEnd === false) { continue; }
                try {
                    $request = json_decode(substr($this->clients[$id]['input'], 0, $lineEnd), true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($request) || array_is_list($request)) { throw new InvalidArgumentException('A command object is required.'); }
                    $response = ['ok' => true, 'result' => ($this->handler)($request)];
                } catch (InvalidArgumentException | JsonException $error) {
                    $response = ['ok' => false, 'error' => $error->getMessage()];
                }
                // Unexpected publication errors are fatal: never acknowledge
                // further commands using state that may not have committed.
                $this->clients[$id]['output'] = json_encode($response, JSON_THROW_ON_ERROR) . "\n";
                $nextTick = 0; // Accepted commands wake admission immediately.
            }
            foreach ($write as $stream) {
                $id = (int) $stream;
                if (!isset($this->clients[$id])) { continue; }
                $written = @fwrite($stream, $this->clients[$id]['output']);
                if ($written === false) { $this->close($id); continue; }
                $this->clients[$id]['output'] = substr($this->clients[$id]['output'], $written);
                if ($this->clients[$id]['output'] === '') { $this->close($id); }
            }
            $now = hrtime(true) / 1e9;
            foreach ($this->clients as $id => $client) { if ($client['deadline'] <= $now) { $this->close($id); } }
        }
    }

    private function close(int $id): void
    {
        if (isset($this->clients[$id])) { fclose($this->clients[$id]['stream']); unset($this->clients[$id]); }
    }

    public function __destruct()
    {
        foreach (array_keys($this->clients) as $id) { $this->close($id); }
        if (is_resource($this->server)) { fclose($this->server); }
        if (isset($this->path)) { @unlink($this->path); }
    }
}

function zfsas_coordinator_request(array $request, string $path = '/var/run/zfs-autosnapshot-coordinator/control.sock', float $timeout = 5): array
{
    $stream = @stream_socket_client('unix://' . $path, $code, $error, $timeout);
    if (!$stream) { throw new RuntimeException('Coordinator unavailable: ' . $error); }
    stream_set_blocking($stream, false);
    $deadline = hrtime(true) / 1e9 + $timeout;
    $output = json_encode($request, JSON_THROW_ON_ERROR) . "\n"; $input = '';
    try {
        while (($remaining = $deadline - hrtime(true) / 1e9) > 0) {
            $read = $output === '' ? [$stream] : []; $write = $output === '' ? [] : [$stream]; $except = null;
            if (@stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6)) === false) { continue; }
            if ($write) {
                $written = fwrite($stream, $output);
                if ($written === false) { throw new RuntimeException('Coordinator request write failed.'); }
                $output = substr($output, $written);
            }
            if ($read) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) { throw new RuntimeException('Coordinator disconnected before acknowledgement. Retry the same command ID.'); }
                $input .= $chunk;
                if (strlen($input) > 8 * 1048576) { throw new RuntimeException('Coordinator response too large.'); }
                if (str_contains($input, "\n")) {
                    $response = json_decode(strstr($input, "\n", true), true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($response) || !isset($response['ok'])) { throw new RuntimeException('Invalid coordinator response.'); }
                    return $response;
                }
            }
        }
        throw new RuntimeException('Coordinator acknowledgement timed out. Retry the same command ID.');
    } finally { fclose($stream); }
}
