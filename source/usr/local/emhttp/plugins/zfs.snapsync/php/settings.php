<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !defined('ZFSAS_WORKSPACE')) {
    $_GET['section'] = $_GET['section'] ?? 'snapshots';
    if ($_GET['section'] === 'snapshots') { $_GET['tab'] = $_GET['tab'] ?? 'automation'; }
    require __DIR__ . '/workspace.php';
    return;
} else {
$pluginName = 'zfs.snapsync';
$settingsPagePath = '/Settings/ZFSSnapSync';
$configDir = "/boot/config/plugins/{$pluginName}";
$configFile = "{$configDir}/zfs_snapsync.conf";
$sendConfigFile = "{$configDir}/zfs_send.conf";
$syncScript = "/usr/local/emhttp/plugins/{$pluginName}/scripts/sync-cron.sh";
$logApiUrl = "/plugins/{$pluginName}/php/log-tail.php";
$logStreamApiUrl = "/plugins/{$pluginName}/php/log-stream.php";
$runApiUrl = "/plugins/{$pluginName}/php/run-now.php";
$saveApiUrl = "/plugins/{$pluginName}/php/save-settings.php";
$diagnosticsApiUrl = "/plugins/zfs.snapsync/php/diagnostics.php";
$sendSettingsUrl = "/plugins/{$pluginName}/php/send-settings.php";
$migrateDatasetsUrl = "/plugins/{$pluginName}/php/migrate-datasets.php";
$snapshotManagerPageUrl = "/plugins/{$pluginName}/php/snapshot-manager-page.php";
$snapshotManagerEmbeddedUrl = $snapshotManagerPageUrl . '?embedded=1';
$snapshotManagerListUrl = "/plugins/{$pluginName}/php/snapshot-manager-list.php";
$snapshotManagerDatasetUrl = "/plugins/{$pluginName}/php/snapshot-manager-dataset.php";
$snapshotManagerActionUrl = "/plugins/{$pluginName}/php/snapshot-manager-action.php";
$logPollIntervalMs = 2000;

require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/send-helpers.php';

$csrfToken = zfsas_get_csrf_token();

$defaults = zfsas_auto_defaults();

$weekdayNames = [
    '0' => 'Sunday',
    '1' => 'Monday',
    '2' => 'Tuesday',
    '3' => 'Wednesday',
    '4' => 'Thursday',
    '5' => 'Friday',
    '6' => 'Saturday',
];

$sendDefaults = zfsas_send_defaults();

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function isAjaxSaveRequest()
{
    if (($_POST['ajax'] ?? '') === 'save') {
        return true;
    }

    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return is_string($requestedWith) && strcasecmp($requestedWith, 'XMLHttpRequest') === 0;
}

function currentRequestUriWithQuery($replacements = [])
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '') {
        return '';
    }

    $parts = parse_url($requestUri);
    $path = (string) ($parts['path'] ?? '');
    if ($path === '') {
        return '';
    }

    $query = [];
    if (isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    foreach ($replacements as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $queryString = http_build_query($query);
    return ($queryString === '') ? $path : ($path . '?' . $queryString);
}

function pluginSettingsPageUrl($fallbackPath, $replacements = [])
{
    $fallbackPath = trim((string) $fallbackPath);
    if ($fallbackPath === '') {
        $fallbackPath = '/Settings/ZFSSnapSync';
    }

    $parts = parse_url($fallbackPath);
    if (!is_array($parts)) {
        $parts = ['path' => '/Settings/ZFSSnapSync'];
    }

    $path = trim((string) ($parts['path'] ?? ''));
    if ($path === '') {
        $path = '/Settings/ZFSSnapSync';
    }

    $query = [];
    if (isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    foreach ($replacements as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $queryString = http_build_query($query);
    return ($queryString === '') ? $path : ($path . '?' . $queryString);
}

function trimValue($value)
{
    return trim((string) $value);
}

function isValidDatasetName($dataset)
{
    return zfsas_is_valid_dataset_name($dataset);
}

function datasetPoolName($dataset)
{
    $dataset = trimValue($dataset);
    if ($dataset === '') {
        return '';
    }

    $parts = explode('/', $dataset, 2);
    return $parts[0];
}

function normalizeThreshold($value)
{
    $value = strtoupper(str_replace(' ', '', trim((string) $value)));

    if (preg_match('/^([0-9]+)([KMGT])B?$/', $value, $match) !== 1) {
        return null;
    }

    return $match[1] . $match[2];
}

function detectInstalledPluginVersion($pluginName)
{
    $pluginName = trim((string) $pluginName);
    if ($pluginName === '') {
        return 'unknown';
    }

    $manifestPaths = [
        "/var/log/plugins/{$pluginName}.plg",
        "/boot/config/plugins/{$pluginName}.plg",
    ];

    foreach ($manifestPaths as $manifestPath) {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            continue;
        }

        $xmlHead = @file_get_contents($manifestPath, false, null, 0, 8192);
        if (!is_string($xmlHead) || $xmlHead === '') {
            continue;
        }

        if (preg_match('/<PLUGIN\b[^>]*\bversion="([^"]+)"/i', $xmlHead, $match) === 1) {
            $version = trimValue($match[1]);
            if ($version !== '') {
                return $version;
            }
        }
    }

    $packagePattern = '/var/log/packages/zfs-snapsync-*';
    $packageFiles = glob($packagePattern);
    if (is_array($packageFiles) && count($packageFiles) > 0) {
        natsort($packageFiles);
        $latest = (string) end($packageFiles);
        $packageName = basename($latest);
        if (preg_match('/^zfs-snapsync-(.+)-[^-]+-[0-9]+$/', $packageName, $match) === 1) {
            $version = trimValue($match[1]);
            if ($version !== '') {
                return $version;
            }
        }
    }

    return 'unknown';
}

function parseConfigFile($path, $defaults)
{
    $config = $defaults;

    if (!is_file($path)) {
        return $config;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $config;
    }

    foreach ($lines as $line) {
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $match)) {
            continue;
        }

        $key = $match[1];
        $raw = trim($match[2]);

        if (!array_key_exists($key, $config)) {
            continue;
        }

        if ($raw === '') {
            $config[$key] = '';
            continue;
        }

        if ($raw[0] === '"' && substr($raw, -1) === '"' && strlen($raw) >= 2) {
            $raw = substr($raw, 1, -1);
            $raw = str_replace(['\\"', '\\\\'], ['"', '\\'], $raw);
            $config[$key] = $raw;
            continue;
        }

        if ($raw[0] === "'" && substr($raw, -1) === "'" && strlen($raw) >= 2) {
            $config[$key] = substr($raw, 1, -1);
            continue;
        }

        $config[$key] = $raw;
    }

    return $config;
}

function parseDatasetsCsv($datasetsCsv, &$warnings = [])
{
    $warnings = [];
    $map = [];

    $parts = explode(',', (string) $datasetsCsv);
    foreach ($parts as $part) {
        $entry = trim($part);
        if ($entry === '') {
            continue;
        }

        if (strpos($entry, ':') === false) {
            $warnings[] = "Ignoring invalid DATASETS entry '{$entry}' (missing ':').";
            continue;
        }

        list($datasetRaw, $thresholdRaw) = explode(':', $entry, 2);
        $dataset = trimValue($datasetRaw);
        $threshold = normalizeThreshold($thresholdRaw);

        if (!isValidDatasetName($dataset)) {
            $warnings[] = "Ignoring invalid dataset name '{$dataset}'.";
            continue;
        }

        if ($threshold === null) {
            $warnings[] = "Ignoring invalid threshold '{$thresholdRaw}' for dataset '{$dataset}'.";
            continue;
        }

        $map[$dataset] = $threshold;
    }

    return $map;
}

function listZfsDatasets(&$errorMessage = null)
{
    $errorMessage = null;
    $datasets = [];
    $poolNames = [];
    $poolExitCode = 0;

    @exec('zpool list -H -o name 2>/dev/null', $poolNames, $poolExitCode);

    if ($poolExitCode === 0 && count($poolNames) > 0) {
        $poolScanErrors = [];

        foreach ($poolNames as $poolLine) {
            $pool = trimValue($poolLine);
            if ($pool === '') {
                continue;
            }

            $poolOutput = [];
            $poolListExitCode = 0;
            // Include both filesystems and zvols, scanning each pool independently.
            @exec('zfs list -H -o name -t filesystem,volume -r ' . escapeshellarg($pool) . ' 2>/dev/null', $poolOutput, $poolListExitCode);

            if ($poolListExitCode !== 0) {
                $poolScanErrors[] = $pool;
                continue;
            }

            foreach ($poolOutput as $line) {
                $dataset = trimValue($line);
                if ($dataset === '' || !isValidDatasetName($dataset)) {
                    continue;
                }
                $datasets[$dataset] = true;
            }
        }

        if (count($datasets) > 0) {
            $list = array_keys($datasets);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);

            if (count($poolScanErrors) > 0) {
                $errorMessage = 'Some pools could not be scanned: ' . implode(', ', $poolScanErrors);
            }

            return $list;
        }

        if (count($poolScanErrors) > 0) {
            $errorMessage = 'Could not auto-discover datasets from these pools: ' . implode(', ', $poolScanErrors);
        }
    }

    // Fallback: aggregate list in case per-pool scan is unavailable.
    $output = [];
    $exitCode = 0;
    @exec('zfs list -H -o name -t filesystem,volume 2>/dev/null', $output, $exitCode);

    if ($exitCode !== 0) {
        if ($errorMessage === null) {
            $errorMessage = 'Could not auto-discover datasets from ZFS on this page load.';
        }
        return [];
    }

    foreach ($output as $line) {
        $dataset = trimValue($line);
        if ($dataset === '' || !isValidDatasetName($dataset)) {
            continue;
        }
        $datasets[$dataset] = true;
    }

    $list = array_keys($datasets);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);

    return $list;
}

function sortDatasetRows($rows)
{
    usort($rows, function ($a, $b) {
        $poolCompare = strnatcasecmp((string) ($a['pool'] ?? ''), (string) ($b['pool'] ?? ''));
        if ($poolCompare !== 0) {
            return $poolCompare;
        }

        $datasetCompare = strnatcasecmp((string) ($a['dataset'] ?? ''), (string) ($b['dataset'] ?? ''));
        if ($datasetCompare !== 0) {
            return $datasetCompare;
        }

        if (($a['available'] ?? false) === ($b['available'] ?? false)) {
            return 0;
        }

        return ($a['available'] ?? false) ? -1 : 1;
    });

    return $rows;
}

function buildDatasetPools($datasetRows)
{
    $poolMap = [];

    foreach ($datasetRows as $row) {
        $pool = (string) ($row['pool'] ?? '');
        if ($pool === '') {
            continue;
        }

        if (!isset($poolMap[$pool])) {
            $poolMap[$pool] = ['total' => 0, 'selected' => 0];
        }

        $poolMap[$pool]['total']++;
        if (!empty($row['selected'])) {
            $poolMap[$pool]['selected']++;
        }
    }

    ksort($poolMap, SORT_NATURAL | SORT_FLAG_CASE);
    return $poolMap;
}

function buildDatasetRows($availableDatasets, $configuredDatasetMap, $sendDestinationDatasets = [])
{
    $rows = [];
    $seen = [];

    foreach ($availableDatasets as $dataset) {
        $pool = datasetPoolName($dataset);
        $locked = isset($sendDestinationDatasets[$dataset]);
        $rows[] = [
            'dataset' => $dataset,
            'pool' => $pool,
            'selected' => (!$locked && isset($configuredDatasetMap[$dataset])),
            'threshold' => isset($configuredDatasetMap[$dataset]) ? $configuredDatasetMap[$dataset] : '100G',
            'available' => true,
            'locked' => $locked,
        ];
        $seen[$dataset] = true;
    }

    foreach ($configuredDatasetMap as $dataset => $threshold) {
        if (isset($seen[$dataset])) {
            continue;
        }

        $pool = datasetPoolName($dataset);
        $locked = isset($sendDestinationDatasets[$dataset]);
        $rows[] = [
            'dataset' => $dataset,
            'pool' => $pool,
            'selected' => !$locked,
            'threshold' => $threshold,
            'available' => false,
            'locked' => $locked,
        ];
    }

    return sortDatasetRows($rows);
}

function buildDatasetRowsFromPost($postedNames, $postedSelected, $postedThresholds, $availableDatasets, $configuredDatasetMap, $sendDestinationDatasets = [])
{
    $rows = [];
    $seen = [];
    $availableSet = array_fill_keys($availableDatasets, true);

    foreach ($postedNames as $index => $datasetRaw) {
        $dataset = trimValue($datasetRaw);
        if ($dataset === '' || isset($seen[$dataset])) {
            continue;
        }

        if (!isValidDatasetName($dataset)) {
            continue;
        }
        $seen[$dataset] = true;

        $threshold = trimValue($postedThresholds[$index] ?? '');
        if ($threshold === '' && isset($configuredDatasetMap[$dataset])) {
            $threshold = $configuredDatasetMap[$dataset];
        }
        if ($threshold === '') {
            $threshold = '100G';
        }

        $rows[] = [
            'dataset' => $dataset,
            'pool' => datasetPoolName($dataset),
            'selected' => (!isset($sendDestinationDatasets[$dataset]) && isset($postedSelected[$index]) && (string) $postedSelected[$index] === '1'),
            'threshold' => strtoupper(str_replace(' ', '', $threshold)),
            'available' => isset($availableSet[$dataset]),
            'locked' => isset($sendDestinationDatasets[$dataset]),
        ];
    }

    return sortDatasetRows($rows);
}

function buildDatasetsCsvFromPost($postedNames, $postedSelected, $postedThresholds, &$errors, $sendDestinationDatasets = [])
{
    $entries = [];
    $seen = [];
    $selectedCount = 0;

    foreach ($postedNames as $index => $datasetRaw) {
        $dataset = trimValue($datasetRaw);
        if ($dataset === '') {
            continue;
        }

        if (isset($seen[$dataset])) {
            continue;
        }
        $seen[$dataset] = true;

        $isLocked = isset($sendDestinationDatasets[$dataset]);
        $isSelected = (!$isLocked && isset($postedSelected[$index]) && (string) $postedSelected[$index] === '1');
        if (!$isSelected) {
            continue;
        }

        $selectedCount++;

        if (!isValidDatasetName($dataset)) {
            $errors[] = "Invalid dataset name '{$dataset}'.";
            continue;
        }

        $threshold = normalizeThreshold($postedThresholds[$index] ?? '');
        if ($threshold === null) {
            $errors[] = "Dataset '{$dataset}' has an invalid threshold. Use values like 500M, 100G, or 2T.";
            continue;
        }

        $entries[] = "{$dataset}:{$threshold}";
    }

    if ($selectedCount === 0) {
        $errors[] = 'Select at least one dataset for automatic snapshots.';
        return null;
    }

    if (count($entries) !== $selectedCount) {
        return null;
    }

    if (count($entries) === 0) {
        $errors[] = 'No valid selected dataset entries were found.';
        return null;
    }

    return implode(',', $entries);
}

function intInRange($value, $min, $max, $label, &$errors)
{
    $value = trim((string) $value);

    if (!preg_match('/^[0-9]+$/', $value)) {
        $errors[] = "{$label} must be an integer ({$min}-{$max}).";
        return null;
    }

    $int = (int) $value;
    if ($int < $min || $int > $max) {
        $errors[] = "{$label} must be between {$min} and {$max}.";
        return null;
    }

    return (string) $int;
}

function normalizeWeekday($value)
{
    $value = strtolower(trim((string) $value));
    $map = [
        '0' => '0',
        '7' => '0',
        'sun' => '0',
        'sunday' => '0',
        '1' => '1',
        'mon' => '1',
        'monday' => '1',
        '2' => '2',
        'tue' => '2',
        'tues' => '2',
        'tuesday' => '2',
        '3' => '3',
        'wed' => '3',
        'wednesday' => '3',
        '4' => '4',
        'thu' => '4',
        'thur' => '4',
        'thurs' => '4',
        'thursday' => '4',
        '5' => '5',
        'fri' => '5',
        'friday' => '5',
        '6' => '6',
        'sat' => '6',
        'saturday' => '6',
    ];

    return $map[$value] ?? null;
}

function cronHasFiveFields($cron)
{
    $parts = preg_split('/\s+/', trim((string) $cron));
    return is_array($parts) && count($parts) === 5;
}

function cronHasSafeCharacters($cron)
{
    return preg_match('/^[A-Za-z0-9*\/,\- ]+$/', trim((string) $cron)) === 1;
}

function buildCronFromSettings($config, &$errors)
{
    $mode = strtolower(trim((string) ($config['SCHEDULE_MODE'] ?? 'disabled')));

    if ($mode === '') {
        $mode = 'disabled';
    }

    if ($mode === 'disabled') {
        return '';
    }

    if ($mode === 'minutes') {
        $every = intInRange($config['SCHEDULE_EVERY_MINUTES'] ?? '15', 1, 59, 'Every N minutes', $errors);
        if ($every === null) {
            return '';
        }
        return ((int) $every === 1) ? '* * * * *' : "*/{$every} * * * *";
    }

    if ($mode === 'hourly') {
        $every = intInRange($config['SCHEDULE_EVERY_HOURS'] ?? '1', 1, 24, 'Every N hours', $errors);
        if ($every === null) {
            return '';
        }
        return ((int) $every === 1) ? '0 * * * *' : "0 */{$every} * * *";
    }

    if ($mode === 'daily') {
        $hour = intInRange($config['SCHEDULE_DAILY_HOUR'] ?? '3', 0, 23, 'Daily hour', $errors);
        $minute = intInRange($config['SCHEDULE_DAILY_MINUTE'] ?? '0', 0, 59, 'Daily minute', $errors);
        if ($hour === null || $minute === null) {
            return '';
        }
        return "{$minute} {$hour} * * *";
    }

    if ($mode === 'weekly') {
        $day = normalizeWeekday($config['SCHEDULE_WEEKLY_DAY'] ?? '0');
        if ($day === null) {
            $errors[] = 'Weekly day must be 0-6 or a weekday name.';
            return '';
        }
        $hour = intInRange($config['SCHEDULE_WEEKLY_HOUR'] ?? '3', 0, 23, 'Weekly hour', $errors);
        $minute = intInRange($config['SCHEDULE_WEEKLY_MINUTE'] ?? '0', 0, 59, 'Weekly minute', $errors);
        if ($hour === null || $minute === null) {
            return '';
        }
        return "{$minute} {$hour} * * {$day}";
    }

    if ($mode === 'custom') {
        $cron = trim((string) ($config['CUSTOM_CRON_SCHEDULE'] ?? ''));
        if ($cron === '') {
            $errors[] = 'Custom cron mode requires a cron expression.';
            return '';
        }
        if (!cronHasFiveFields($cron)) {
            $errors[] = 'Custom cron expression must have exactly 5 fields.';
            return '';
        }
        if (!cronHasSafeCharacters($cron)) {
            $errors[] = 'Custom cron expression contains unsupported characters.';
            return '';
        }
        return $cron;
    }

    $errors[] = "Invalid schedule mode '{$mode}'.";
    return '';
}

function quoteConfigString($value)
{
    $value = str_replace('\\', '\\\\', (string) $value);
    $value = str_replace('"', '\\"', $value);
    return '"' . $value . '"';
}

function renderConfig($config)
{
    $lines = [];
    $lines[] = '# -----------------------------------------------------------------------------';
    $lines[] = '# ZFS SnapSync plugin config';
    $lines[] = '# Path: /boot/config/plugins/zfs.snapsync/zfs_snapsync.conf';
    $lines[] = '# -----------------------------------------------------------------------------';
    $lines[] = '';
    $lines[] = '# DATASETS: comma-separated dataset:threshold entries';
    $lines[] = 'DATASETS=' . quoteConfigString($config['DATASETS']);
    $lines[] = '';
    $lines[] = '# Snapshot name prefix this plugin is allowed to delete';
    $lines[] = 'PREFIX=' . quoteConfigString($config['PREFIX']);
    $lines[] = '';
    $lines[] = '# 1 = dry-run only, 0 = make changes';
    $lines[] = 'DRY_RUN=' . $config['DRY_RUN'];
    $lines[] = '';
    $lines[] = '# Retention windows in days';
    $lines[] = 'KEEP_ALL_FOR_DAYS=' . $config['KEEP_ALL_FOR_DAYS'];
    $lines[] = 'KEEP_DAILY_UNTIL_DAYS=' . $config['KEEP_DAILY_UNTIL_DAYS'];
    $lines[] = 'KEEP_WEEKLY_UNTIL_DAYS=' . $config['KEEP_WEEKLY_UNTIL_DAYS'];
    $lines[] = '';
    $lines[] = '# Human-friendly schedule fields';
    $lines[] = 'SCHEDULE_MODE=' . quoteConfigString($config['SCHEDULE_MODE']);
    $lines[] = 'SCHEDULE_EVERY_MINUTES=' . $config['SCHEDULE_EVERY_MINUTES'];
    $lines[] = 'SCHEDULE_EVERY_HOURS=' . $config['SCHEDULE_EVERY_HOURS'];
    $lines[] = 'SCHEDULE_DAILY_HOUR=' . $config['SCHEDULE_DAILY_HOUR'];
    $lines[] = 'SCHEDULE_DAILY_MINUTE=' . $config['SCHEDULE_DAILY_MINUTE'];
    $lines[] = 'SCHEDULE_WEEKLY_DAY=' . $config['SCHEDULE_WEEKLY_DAY'];
    $lines[] = 'SCHEDULE_WEEKLY_HOUR=' . $config['SCHEDULE_WEEKLY_HOUR'];
    $lines[] = 'SCHEDULE_WEEKLY_MINUTE=' . $config['SCHEDULE_WEEKLY_MINUTE'];
    $lines[] = 'CUSTOM_CRON_SCHEDULE=' . quoteConfigString($config['CUSTOM_CRON_SCHEDULE']);
    $lines[] = '';
    $lines[] = '# Derived cron expression (for compatibility)';
    $lines[] = 'CRON_SCHEDULE=' . quoteConfigString($config['CRON_SCHEDULE']);
    $lines[] = '';

    return implode("\n", $lines);
}

$isPostRequest = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$isAjaxSaveRequest = ((defined('ZFSAS_FORCE_AJAX_SAVE') && ZFSAS_FORCE_AJAX_SAVE) || ($isPostRequest && isAjaxSaveRequest()));

$installedVersion = $isAjaxSaveRequest ? '' : detectInstalledPluginVersion($pluginName);
$defaultSettingsReturnUrl = pluginSettingsPageUrl($settingsPagePath, ['section' => 'snapshots', 'tab' => 'automation', 'saved' => null]);

$pageConfig = zfsas_config_read_pair($configDir);
$config = array_merge($defaults, $pageConfig['auto']);
$errors = [];
$notices = [];
$datasetParseWarnings = [];
$configuredDatasetMap = parseDatasetsCsv($config['DATASETS'], $datasetParseWarnings);
$availableDatasets = [];
$sendConfig = $pageConfig['send'];
$sendParseErrors = [];
$sendParseWarnings = [];
$sendJobs = zfsas_send_parse_jobs($sendConfig['SEND_JOBS'] ?? '', $sendParseErrors, $sendParseWarnings);
$sendDestinationCandidates = array_values(array_unique(array_merge($availableDatasets, array_keys($configuredDatasetMap))));
$sendDestinationDatasets = zfsas_send_destination_datasets_from_jobs($sendJobs, $sendDestinationCandidates);
$initialSection = trim((string) ($_GET['section'] ?? 'main'));
if (!in_array($initialSection, ['main', 'special-features', 'snapshot-manager', 'help'], true)) {
    $initialSection = 'main';
}

if (!$isAjaxSaveRequest && (($_GET['saved'] ?? '') === '1')) {
    $notices[] = 'Settings saved and schedule applied.';
}

$datasetDiscoveryError = null;
$datasetRows = [];
$datasetPools = [];

// Render saved selections immediately; ZFS discovery is a bounded asynchronous request.
$datasetRows = buildDatasetRows([], $configuredDatasetMap, $sendDestinationDatasets);
$datasetPools = buildDatasetPools($datasetRows);

if (!$isAjaxSaveRequest && !empty($datasetParseWarnings)) {
    foreach ($datasetParseWarnings as $warning) {
        $notices[] = $warning;
    }
}

if (!$isAjaxSaveRequest && !empty($sendParseWarnings)) {
    foreach ($sendParseWarnings as $warning) {
        $notices[] = $warning;
    }
}

if ($isPostRequest) {
    $csrfError = null;
    if (!zfsas_validate_csrf_token($csrfError)) {
        if ($isAjaxSaveRequest) {
            zfsas_emit_marked_json([
                'ok' => false,
                'errors' => [$csrfError],
                'notices' => [],
            ], 403);
        }

        $errors[] = $csrfError;
    }

    $submitted = $config;

    $submitted['PREFIX'] = trimValue($_POST['prefix'] ?? $submitted['PREFIX']);
    $submitted['DRY_RUN'] = isset($_POST['dry_run']) ? '1' : '0';
    $submitted['KEEP_ALL_FOR_DAYS'] = trimValue($_POST['keep_all_for_days'] ?? $submitted['KEEP_ALL_FOR_DAYS']);
    $submitted['KEEP_DAILY_UNTIL_DAYS'] = trimValue($_POST['keep_daily_until_days'] ?? $submitted['KEEP_DAILY_UNTIL_DAYS']);
    $submitted['KEEP_WEEKLY_UNTIL_DAYS'] = trimValue($_POST['keep_weekly_until_days'] ?? $submitted['KEEP_WEEKLY_UNTIL_DAYS']);
    $submitted['SCHEDULE_MODE'] = strtolower(trimValue($_POST['schedule_mode'] ?? $submitted['SCHEDULE_MODE']));
    $submitted['SCHEDULE_EVERY_MINUTES'] = trimValue($_POST['schedule_every_minutes'] ?? $submitted['SCHEDULE_EVERY_MINUTES']);
    $submitted['SCHEDULE_EVERY_HOURS'] = trimValue($_POST['schedule_every_hours'] ?? $submitted['SCHEDULE_EVERY_HOURS']);
    $submitted['SCHEDULE_DAILY_HOUR'] = trimValue($_POST['schedule_daily_hour'] ?? $submitted['SCHEDULE_DAILY_HOUR']);
    $submitted['SCHEDULE_DAILY_MINUTE'] = trimValue($_POST['schedule_daily_minute'] ?? $submitted['SCHEDULE_DAILY_MINUTE']);
    $submitted['SCHEDULE_WEEKLY_DAY'] = trimValue($_POST['schedule_weekly_day'] ?? $submitted['SCHEDULE_WEEKLY_DAY']);
    $submitted['SCHEDULE_WEEKLY_HOUR'] = trimValue($_POST['schedule_weekly_hour'] ?? $submitted['SCHEDULE_WEEKLY_HOUR']);
    $submitted['SCHEDULE_WEEKLY_MINUTE'] = trimValue($_POST['schedule_weekly_minute'] ?? $submitted['SCHEDULE_WEEKLY_MINUTE']);
    $submitted['CUSTOM_CRON_SCHEDULE'] = trimValue($_POST['custom_cron_schedule'] ?? $submitted['CUSTOM_CRON_SCHEDULE']);

    $postDatasetNames = (isset($_POST['dataset_name']) && is_array($_POST['dataset_name'])) ? $_POST['dataset_name'] : [];
    $postDatasetSelected = (isset($_POST['dataset_selected']) && is_array($_POST['dataset_selected'])) ? $_POST['dataset_selected'] : [];
    $postDatasetThresholds = (isset($_POST['dataset_threshold']) && is_array($_POST['dataset_threshold'])) ? $_POST['dataset_threshold'] : [];

    $datasetRows = buildDatasetRowsFromPost($postDatasetNames, $postDatasetSelected, $postDatasetThresholds, $availableDatasets, $configuredDatasetMap, $sendDestinationDatasets);
    $datasetPools = buildDatasetPools($datasetRows);

    if (count($postDatasetNames) === 0) {
        $errors[] = 'Dataset selection data was not submitted. Refresh the page and try again.';
    } else {
        $datasetCsv = buildDatasetsCsvFromPost($postDatasetNames, $postDatasetSelected, $postDatasetThresholds, $errors, $sendDestinationDatasets);
        if ($datasetCsv !== null) {
            $submitted['DATASETS'] = $datasetCsv;
        }
    }

    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $submitted['PREFIX'])) {
        $errors[] = 'Prefix can only contain letters, numbers, dot, underscore, colon, and dash.';
    }

    $sendSnapshotPrefix = (string) ($sendConfig['SEND_SNAPSHOT_PREFIX'] ?? 'snapsync-send-');
    if (zfsas_snapshot_prefixes_conflict($submitted['PREFIX'], $sendSnapshotPrefix)) {
        $errors[] = zfsas_snapshot_prefix_conflict_message($submitted['PREFIX'], $sendSnapshotPrefix);
    }

    $submitted['KEEP_ALL_FOR_DAYS'] = intInRange($submitted['KEEP_ALL_FOR_DAYS'], 1, 36500, 'Keep all for days', $errors) ?? $submitted['KEEP_ALL_FOR_DAYS'];
    $submitted['KEEP_DAILY_UNTIL_DAYS'] = intInRange($submitted['KEEP_DAILY_UNTIL_DAYS'], 2, 36500, 'Keep daily until days', $errors) ?? $submitted['KEEP_DAILY_UNTIL_DAYS'];
    $submitted['KEEP_WEEKLY_UNTIL_DAYS'] = intInRange($submitted['KEEP_WEEKLY_UNTIL_DAYS'], 3, 36500, 'Keep weekly until days', $errors) ?? $submitted['KEEP_WEEKLY_UNTIL_DAYS'];

    if (empty($errors)) {
        if ((int) $submitted['KEEP_ALL_FOR_DAYS'] >= (int) $submitted['KEEP_DAILY_UNTIL_DAYS'] ||
            (int) $submitted['KEEP_DAILY_UNTIL_DAYS'] >= (int) $submitted['KEEP_WEEKLY_UNTIL_DAYS']) {
            $errors[] = 'Retention must follow: keep all < keep daily until < keep weekly until.';
        }
    }

    if (!in_array($submitted['SCHEDULE_MODE'], ['disabled', 'minutes', 'hourly', 'daily', 'weekly', 'custom'], true)) {
        $errors[] = 'Schedule mode is invalid.';
    }

    switch ($submitted['SCHEDULE_MODE']) {
        case 'minutes':
            $submitted['SCHEDULE_EVERY_MINUTES'] = intInRange($submitted['SCHEDULE_EVERY_MINUTES'], 1, 59, 'Every N minutes', $errors) ?? $submitted['SCHEDULE_EVERY_MINUTES'];
            break;
        case 'hourly':
            $submitted['SCHEDULE_EVERY_HOURS'] = intInRange($submitted['SCHEDULE_EVERY_HOURS'], 1, 24, 'Every N hours', $errors) ?? $submitted['SCHEDULE_EVERY_HOURS'];
            break;
        case 'daily':
            $submitted['SCHEDULE_DAILY_HOUR'] = intInRange($submitted['SCHEDULE_DAILY_HOUR'], 0, 23, 'Daily hour', $errors) ?? $submitted['SCHEDULE_DAILY_HOUR'];
            $submitted['SCHEDULE_DAILY_MINUTE'] = intInRange($submitted['SCHEDULE_DAILY_MINUTE'], 0, 59, 'Daily minute', $errors) ?? $submitted['SCHEDULE_DAILY_MINUTE'];
            break;
        case 'weekly':
            $normalizedWeekday = normalizeWeekday($submitted['SCHEDULE_WEEKLY_DAY']);
            if ($normalizedWeekday === null) {
                $errors[] = 'Weekly day must be a day number (0-6) or weekday name.';
            } else {
                $submitted['SCHEDULE_WEEKLY_DAY'] = $normalizedWeekday;
            }
            $submitted['SCHEDULE_WEEKLY_HOUR'] = intInRange($submitted['SCHEDULE_WEEKLY_HOUR'], 0, 23, 'Weekly hour', $errors) ?? $submitted['SCHEDULE_WEEKLY_HOUR'];
            $submitted['SCHEDULE_WEEKLY_MINUTE'] = intInRange($submitted['SCHEDULE_WEEKLY_MINUTE'], 0, 59, 'Weekly minute', $errors) ?? $submitted['SCHEDULE_WEEKLY_MINUTE'];
            break;
    }

    $cron = buildCronFromSettings($submitted, $errors);
    $submitted['CRON_SCHEDULE'] = $cron;

    $submitted['__convert_schedule'] = ($_POST['convert_schedule'] ?? '') === '1';
    $config = $submitted;

    if (empty($errors)) {
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0775, true);
        }

        $saveResult = zfsas_config_save('auto', $configDir, $config, $_POST['config_revision'] ?? null, 'renderConfig', $syncScript);
        $errors = array_merge($errors, $saveResult['errors']);
        $notices = array_merge($notices, $saveResult['notices']);
    }

    if ($isAjaxSaveRequest) {
        $ajaxNotices = $notices;
        $ajaxErrors = $errors;
        $ajaxResolvedCron = trim((string) ($config['CRON_SCHEDULE'] ?? ''));
        if ($ajaxResolvedCron === '') {
            $ajaxResolvedCron = '(disabled)';
        }

        zfsas_emit_marked_json([
            'ok' => empty($ajaxErrors),
            'errors' => array_values($ajaxErrors),
            'notices' => array_values($ajaxNotices),
            'saved' => $saveResult['saved'] ?? false,
            'schedulerApplied' => $saveResult['schedulerApplied'] ?? false,
            'revision' => $saveResult['revision'] ?? zfsas_config_revision($configDir),
            'resolvedCron' => $ajaxResolvedCron,
            'prefix' => (string) ($config['PREFIX'] ?? ''),
        ], empty($ajaxErrors) ? 200 : 400);
    }
}

$resolvedCron = trim((string) ($config['CRON_SCHEDULE'] ?? ''));
if ($resolvedCron === '') {
    $resolvedCron = '(disabled)';
}
$renderStandalonePage = !empty($GLOBALS['zfsas_render_standalone_page']);
?>
<?php require __DIR__ . '/views/automation.php'; ?>

<?php } ?>
