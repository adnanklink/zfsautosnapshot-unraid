<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !defined('ZFSAS_WORKSPACE')) {
    $_GET['section'] = 'tools';
    $_GET['tab'] = 'migrator';
    require __DIR__ . '/workspace.php';
    return;
}
$pluginName = 'zfs.autosnapshot';
$statusUrl = "/plugins/{$pluginName}/php/migrate-datasets-status.php";
$actionUrl = "/plugins/{$pluginName}/php/migrate-datasets-action.php";
$mainSettingsUrl = '/Settings/ZFSAutoSnapshot?section=special-features';
require_once __DIR__ . '/response-helpers.php';
$csrfToken = zfsas_get_csrf_token();
?>

<?php require __DIR__ . '/views/migration.php'; ?>
