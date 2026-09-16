<?php
/** Called only after the executor verifies that the deletion process group stopped. */
function zfsas_coordinator_delete_outcome(int $code): array
{
    if ($code !== 0) { return ['outcome' => 'transient_failure', 'exitCode' => $code]; }
    $inbox = zfsas_ops_delete_queue_inbox_path();
    $paths = [$inbox, zfsas_ops_delete_queue_state_path()];
    foreach (glob($inbox . '.processing.*') ?: [] as $path) { $paths[] = $path; }
    foreach ($paths as $path) {
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > 0) {
            // A producer can append and submit while the old worker is exiting.
            // That command belongs to this pump; do not lose it by completing
            // the run solely because the old leader returned success.
            return ['outcome' => 'wait', 'reason' => 'dependency', 'delay' => 1,
                'message' => 'Deletion work remains after worker shutdown.'];
        }
    }
    return ['outcome' => 'success', 'exitCode' => 0];
}
