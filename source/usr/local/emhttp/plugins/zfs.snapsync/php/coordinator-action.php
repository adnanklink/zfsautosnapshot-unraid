<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/coordinator-client.php';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { zfsas_emit_marked_json(['ok' => false, 'error' => 'Use POST.'], 405); }
$error = null;
if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok' => false, 'error' => $error], 403); }
$action = $_POST['action'] ?? '';
if (!in_array($action, ['cancel', 'resume', 'retry'], true)) { zfsas_emit_marked_json(['ok' => false, 'error' => 'Invalid action.'], 400); }
try {
    zfsas_coordinator_ensure();
    $response = zfsas_coordinator_request(['action' => $action, 'runId' => (string) ($_POST['run_id'] ?? '')]);
    zfsas_emit_marked_json($response['ok'] ? ['ok' => true] + $response['result'] : $response, $response['ok'] ? 200 : 409);
} catch (Throwable $error) { zfsas_emit_marked_json(['ok' => false, 'error' => $error->getMessage()], 503); }
