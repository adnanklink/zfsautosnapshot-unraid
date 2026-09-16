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

## Cleanup dependency repair

Queued/waiting sends now protect exact selected snapshots, planned source and
receiver bases, target checkpoints, and resume-token bases. Active attempts retain
whole-tree exclusion until shutdown. Plans capture source dataset and base GUIDs
and revalidate them before transfer. Pool preparation waits for dependent preflight
plans before queueing cleanup. Unplanned work must choose and validate its base
when planned; it cannot pin every snapshot in a dataset while waiting.

Space approval uses measured availability. If planning finds no deletion work,
no pending freeing and no outstanding transfer reservations, the job fails with
required/available byte counts and an explicit free-space/Retry instruction.
Dependency waits leave attempt counts unchanged.

`tests/reliability/dependencies.sh` exercises shared destinations, independent
bases, resume references, unrelated deletion admission, active exclusion, and the
production space-admission function's terminal and waiting paths. Stage-one and
reliability suites pass after these changes. Both Chromium suites passed using
Node 22. The PHP coordinator integration remains outstanding.
