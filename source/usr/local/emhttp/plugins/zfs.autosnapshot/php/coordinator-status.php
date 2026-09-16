<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/coordinator-socket.php';
try {
    $response = zfsas_coordinator_request(['action' => 'status'], '/var/run/zfs-autosnapshot-coordinator/control.sock', 2);
    zfsas_emit_marked_json($response['ok'] ? ['ok' => true, 'available' => true] + $response['result'] : $response);
} catch (Throwable $error) {
    // Status never starts a service or initializes runtime/persistent storage.
    zfsas_emit_marked_json(['ok' => true, 'available' => false, 'runs' => [], 'message' => 'Coordinator is unavailable; the watchdog will retry.']);
}
