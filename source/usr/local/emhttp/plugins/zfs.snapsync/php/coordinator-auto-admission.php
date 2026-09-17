<?php
/** Configuration admission only: no ZFS inspection or mutation. */
function zfsas_coordinator_auto_command(ZfsasCoordinatorState $journal, array $task, array $config, string $root): array
{
    $parameters = $task['parameters'];
    if ($parameters['revision'] !== $config['revision']) {
        $replacement = ['revision' => $config['revision'], 'autoConfig' => $config['rawAuto'],
            'sendConfig' => $config['rawSend'], 'prefixHistory' => $config['prefixHistory'],
            'scheduleSpec' => $config['schedule']];
        // A converted/disabled schedule must obey its new first-run time. Do not
        // turn an old accepted occurrence into an immediate run of the new one.
        $sameSchedule = isset($parameters['scheduleSpec'])
            && $parameters['scheduleSpec'] == $config['schedule']
            && $config['schedule']['kind'] !== 'disabled';
        if (!$sameSchedule || trim($config['auto']['DATASETS'] ?? '') === ''
            || !$journal->replanAuto($task['id'], $config['revision'], $replacement, time())) {
            $manual = $journal->state['runs'][$task['runId']]['manual'];
            return ['outcome' => 'validation_failure', 'reason' => 'configuration',
                'message' => $manual ? 'Configuration changed; review and submit a new run.'
                    : 'Configuration changed; this run cannot be safely replanned. The next scheduled occurrence may run.'];
        }
        $parameters = $replacement;
    }
    $capture = $root . '/config/' . $parameters['revision'];
    if (!is_dir($capture) && !mkdir($capture, 0700, true)) { throw new RuntimeException('Cannot create captured configuration directory.'); }
    foreach (['zfs_snapsync.conf' => 'autoConfig', 'zfs_send.conf' => 'sendConfig', 'send-prefix-history' => 'prefixHistory'] as $file => $key) {
        $path = $capture . '/' . $file;
        // A service crash during capture must not leave a partial file that the
        // next attempt mistakes for its committed configuration.
        if (@file_get_contents($path) === $parameters[$key]) { continue; }
        if (file_put_contents($path . '.pending', $parameters[$key]) !== strlen($parameters[$key])
            || !rename($path . '.pending', $path)) { throw new RuntimeException('Cannot publish captured configuration.'); }
    }
    return ['/usr/bin/env', 'ZFSAS_COORDINATED=1', 'CONFIG_FILE=' . $capture . '/zfs_snapsync.conf',
        'ZFSAS_CONFIG_REVISION=' . $parameters['revision'], '/usr/local/sbin/zfs_snapsync'];
}
