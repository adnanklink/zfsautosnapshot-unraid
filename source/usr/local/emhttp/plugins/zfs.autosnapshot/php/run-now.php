<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/coordinator-client.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Use POST for manual run requests.']); exit; }
$error = null;
if (!zfsas_validate_csrf_token($error)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => $error]); exit; }
try {
    $commandId = (string) ($_POST['command_id'] ?? 'manual-auto-' . bin2hex(random_bytes(16)));
    zfsas_coordinator_ensure();
    $response = zfsas_coordinator_request(['action' => 'auto', 'commandId' => $commandId]);
    if (!$response['ok']) { http_response_code(409); echo json_encode($response); exit; }
    echo json_encode(['ok' => true, 'pid' => 0, 'commandId' => $commandId, 'runId' => $response['result']['runId'],
        'message' => isset($response['result']['blocked']) ? 'An Auto Snapshot run is already active.' : 'Manual Auto Snapshot run accepted.']);
} catch (Throwable $error) { http_response_code(503); echo json_encode(['ok' => false, 'error' => $error->getMessage()]); }
