# Proposed upgrade and removal shutdown changes

Status: source implementation resumed after the user instructed continuation of this documented proposal. Verification is recorded in the reliability audit. No installation or deployment has been performed.

The plugin is not installed on this development host. Implementation would edit repository source only; deployment and execution remain outside this task.

## Exact behavior

1. Add `scripts/worker-shutdown-lib.sh`, used by `scripts/post-install.sh` and `scripts/pre-remove.sh`. Read each `/proc/PID/stat` start time before signaling. Verify that a root process command matches the plugin worker being stopped. Capture its child processes and start times, send TERM only to identities that still match, wait up to ten seconds, then send KILL to matching survivors and verify exit for another two seconds. Ignore zombie processes. Abort installation/removal if any matching process still survives.
2. Use existing `stop_send_process_group` with the persisted `WORKER_PGID` and `WORKER_START` for old replication attempts. Preserve ownership records if shutdown cannot be verified. Remove the broad heuristic that finds unrelated `zfs destroy` commands by snapshot prefix.
3. Preserve permanent flock files, `/tmp/zfs-autosnapshot-ops`, legacy queue records, batch manifests, and cancellation history. Do not recursively clear queue/claim directories during installation. Reconciliation already determines which attempts can restart.
4. Include `php/snapshot-batch-worker.php` in worker shutdown; the old shell launcher now execs PHP.
5. Set `/boot/config/plugins/zfs.autosnapshot/maintenance` while installation/removal runs. New replication, automatic snapshot, delete-daemon, batch-worker and migration launches stop or return a maintenance message while it exists. Successful installation removes the marker. Failed installation or removal leaves it in place to prevent new work; rerunning installation completes recovery.

## Affected source files

- `scripts/post-install.sh`, `scripts/pre-remove.sh`, new `scripts/worker-shutdown-lib.sh`
- `scripts/ops-queue-lib.sh`: maintenance check in the existing runtime prefix/configuration gate
- `sbin/zfs_autosnapshot`, `sbin/zfs_autosnapshot_delete_worker`: startup gate
- `php/snapshot-batch-worker.php`: startup and chunk-loop gate
- `php/snapshot-manager-batch.php`: submission gate
- `php/migrate-datasets-helpers.php`: migration-start gate

## Verification before committing

Use disposable container processes only: a stale PID pointing at an unrelated sleeper must not be signaled; a plugin-marked process with a child must stop completely; a rejected owner must leave runtime state untouched; permanent lock inodes and queued manifests must survive the simulated upgrade. Run Bash/PHP syntax checks, the full stage-one suite, and package verification. No host worker or pool is used for these tests.

## Operational tradeoffs

Shutdown may take up to twelve seconds per surviving process tree. A failed upgrade deliberately leaves the maintenance marker and runtime records for recovery. Old legacy queue requests are retained for inspection rather than replayed without the new identity and approval checks.
