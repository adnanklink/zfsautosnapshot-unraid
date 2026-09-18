<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/interface-settings.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { zfsas_emit_marked_json(['ok' => false, 'error' => 'POST required.'], 405); }
if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok' => false, 'error' => $error], 403); }
if (!in_array($_POST['show_tab'] ?? null, ['0', '1'], true)) { zfsas_emit_marked_json(['ok' => false, 'error' => 'Invalid tab preference.'], 400); }
try {
    $result = zfsas_interface_save('/boot/config/plugins/zfs.snapsync', $_POST['show_tab'] === '1', $_POST['revision'] ?? null);
    zfsas_emit_marked_json(['ok' => true] + $result);
} catch (RuntimeException $error) { zfsas_emit_marked_json(['ok' => false, 'error' => $error->getMessage()], 409); }
