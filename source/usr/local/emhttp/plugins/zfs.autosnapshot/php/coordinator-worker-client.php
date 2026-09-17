<?php
require_once __DIR__ . '/coordinator-socket.php';

/** Report through the current grant. Workers must stop if this throws. */
function zfsas_coordinator_worker_report(string $type, int $sequence, array $payload): array
{
    $task = getenv('ZFSAS_TASK_ID');
    $token = getenv('ZFSAS_ATTEMPT_TOKEN');
    $generation = getenv('ZFSAS_COORDINATOR_GENERATION');
    if (!$task || !$token || !$generation) { throw new RuntimeException('No coordinator worker grant.'); }
    $response = zfsas_coordinator_request(['action' => 'worker_report', 'type' => $type,
        'sequence' => $sequence, 'payload' => $payload, 'taskId' => $task,
        'token' => $token, 'generation' => $generation]);
    if (!$response['ok']) { throw new RuntimeException($response['error'] ?? 'Worker publication rejected.'); }
    return $response['result'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $sequence = $argv[2] ?? '';
        if (!ctype_digit($sequence) || strlen($sequence) > 9 || (int) $sequence < 1) { throw new InvalidArgumentException('Positive report sequence required.'); }
        $input = stream_get_contents(STDIN, 786433);
        if ($input === false || strlen($input) > 786432) { throw new InvalidArgumentException('Worker report is too large.'); }
        $payload = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new InvalidArgumentException('Worker payload must be an object.'); }
        echo json_encode(zfsas_coordinator_worker_report($argv[1] ?? '', (int) $sequence, $payload), JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
