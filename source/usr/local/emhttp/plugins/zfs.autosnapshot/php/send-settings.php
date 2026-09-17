<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !defined('ZFSAS_WORKSPACE')) {
    $_GET['section'] = 'replication';
    require __DIR__ . '/workspace.php';
    return;
} else {
function zfsas_send_queue_path_display($path)
{
    $path = rtrim((string) $path, '/');
    if ($path === '') {
        return '';
    }

    $segments = explode('/', $path);
    return (string) end($segments);
}

$pluginName = 'zfs.autosnapshot';
$configDir = "/boot/config/plugins/{$pluginName}";
$configFile = "{$configDir}/zfs_send.conf";
$syncScript = "/usr/local/emhttp/plugins/{$pluginName}/scripts/sync-cron.sh";
$saveApiUrl = "/plugins/{$pluginName}/php/save-send-settings.php";
$runApiUrl = "/plugins/{$pluginName}/php/run-send-now.php";
$queueStatusApiUrl = "/plugins/{$pluginName}/php/send-queue-status.php";
$queueStreamApiUrl = "/plugins/{$pluginName}/php/send-queue-stream.php";
$queueActionApiUrl = "/plugins/{$pluginName}/php/send-queue-action.php";
$queueLogDownloadApiUrl = "/plugins/{$pluginName}/php/send-log-download.php";
$mainSettingsUrl = '/Settings/ZFSAutoSnapshot?section=special-features';

require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/send-queue-helpers.php';

$csrfToken = zfsas_get_csrf_token();

$defaults = zfsas_send_defaults();

$isPostRequest = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$isAjaxSaveRequest = ((defined('ZFSAS_FORCE_SEND_AJAX_SAVE') && ZFSAS_FORCE_SEND_AJAX_SAVE) || ($isPostRequest && zfsas_send_is_ajax_request()));
$defaultReturnUrl = '/Settings/ZFSAutoSnapshot?section=replication';

$pageConfig = zfsas_config_read_pair($configDir);
$config = $pageConfig['send'];
$errors = [];
$notices = [];
$parseErrors = [];
$parseWarnings = [];
$jobs = zfsas_send_parse_jobs($config['SEND_JOBS'] ?? '', $parseErrors, $parseWarnings);
$formJobs = $jobs;
$queueJobs = zfsas_ops_recent_send_jobs(120);
$pendingDeleteCount = zfsas_ops_pending_delete_job_count();
$datasetDiscoveryError = null;
$availableDatasets = array_values(array_unique(array_column($jobs, 'source')));

if (($_GET['saved'] ?? '') === '1' && !$isPostRequest) {
    $notices[] = 'ZFS send settings saved and schedule applied.';
}

foreach ($parseWarnings as $warning) {
    $notices[] = $warning;
}

if ($isPostRequest) {
    $csrfError = null;
    if (!zfsas_validate_csrf_token($csrfError)) {
        if ($isAjaxSaveRequest) {
            zfsas_emit_marked_json([
                'ok' => false,
                'errors' => [$csrfError],
                'notices' => [],
                'jobCount' => count($formJobs),
            ], 403);
        }

        $errors[] = $csrfError;
    } else {
        $autoSnapshotPrefix = zfsas_read_auto_snapshot_prefix($configDir);
        $saveResult = zfsas_send_handle_save_request($_POST, $configDir, $configFile, $syncScript, $config, $defaultReturnUrl, $autoSnapshotPrefix);
        $config = $saveResult['config'];
        $formJobs = $saveResult['formJobs'];
        $errors = $saveResult['errors'];
        $notices = array_merge($notices, $saveResult['notices']);

        if ($saveResult['saved'] && !$isAjaxSaveRequest) {
            $separator = (strpos($saveResult['returnTarget'], '?') === false) ? '?' : '&';
            zfsas_send_redirect_page(
                $saveResult['returnTarget'] . $separator . 'saved=1',
                'ZFS send settings saved. Returning to the send settings page...'
            );
        }

        if ($isAjaxSaveRequest) {
            zfsas_emit_marked_json([
                'ok' => empty($errors),
                'errors' => array_values($errors),
                'notices' => array_values($notices),
                'saved' => $saveResult['saved'],
                'schedulerApplied' => $saveResult['schedulerApplied'],
                'revision' => $saveResult['revision'],
                'jobCount' => count($formJobs),
            ], empty($errors) ? 200 : 400);
        }
    }
}
?>

<?php require __DIR__ . '/views/replication.php'; ?>

<?php } ?>
