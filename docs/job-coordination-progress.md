# Job coordination implementation record

Branch: `fix/job-coordination`, based on `fix/send-cancellation`.

## RAM runtime storage

Runtime send completion cursors now use `/tmp/zfs-autosnapshot-ops/status`;
bounded failure captures use `/var/log/zfs-autosnapshot-failed-sends`. Deletion
shutdown flushes only its RAM state. The worker neither reads nor writes a
boot-persisted deletion queue. Legacy Snapshot Manager storage and migrator
display state now use `/tmp`. Status polling no longer initializes storage.
Configuration reads and saves share RAM locks with cron application. Identical
configuration and prefix-history content is not rewritten.

Cancel and Resume remain explicit persistent decisions. Cancellation is published
atomically and synchronized before worker signals. Migrator checkpoints remain
on flash, separately from display state. Version 2 checkpoints contain source and
temporary paths, dataset GUIDs, and container restoration identities/policies.
They record rename intent before rename, and verified copy state before deleting
the temporary source. Missing or changed identities require manual recovery.
Legacy recovery checkpoints retain their companion files until recovery finishes.

The installation-only migration script quarantines old deletion and batch
records as review-required evidence in RAM. It never executes them. Historical
send cursors are quarantined, not accepted as proof of current ZFS completion.
Migrator status history, send history and failed logs are lost on reboot.

## Verification to date

- Existing stage-one and reliability suites pass in disposable containers.
- Migrator recovery simulation covers legacy checkpoints and version 2 recovery
  with all folder/container display files removed.
- `tests/reliability/ram_runtime.sh` passes with a read-only `/boot` fixture.
  `strace -f -e trace=%file` reports zero attempted writes or metadata changes to
  `/boot` for runtime layout, cursor publication, failure captures, configuration
  reads, polling, and migrator progress. This is scoped coverage, not yet proof
  for every end-to-end scheduling and worker path.
- Actual batch endpoints pass, including partial failure, failed-only retries,
  identity changes, approval expiry, limits and exact deletion boundaries.
- PHP/Bash parsing and ShellCheck error-level checks pass.
- Existing real-ZFS full/incremental/cancel/resume regression passes with unique
  disposable file-backed pools; a follow-up check found no remaining test pools.
- Temporary package inventory matches source; no release artifacts were updated.

## Outstanding plan scope

Cleanup dependency registration, the PHP socket coordinator and worker ownership
integration, versioned scheduling, new API/UI controls, comprehensive fault and
clock tests, and complete flash-write instrumentation remain to be implemented.
The existing queue manager still owns scheduling/dispatch in this revision.
