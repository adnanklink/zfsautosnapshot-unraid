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
Node 22. The replication executor still uses the existing Bash queue handler; see the remaining scope below.

## Coordinator and scheduling delivered

The PHP CLI service owns Auto Snapshot runs, Snapshot Manager batch attempts and
shared deletion-daemon launch attempts. Its Unix socket remains responsive to
partial clients and concurrent configuration saves. A versioned, checksummed RAM
checkpoint publishes commands before acknowledgment. Stable command IDs preserve
idempotency during service restart. Each worker waits for a grant after its PID,
start time and process group have been recorded. Recovery stops and verifies old
process groups before admitting another attempt. Pipeline children must also stop.

The state model separates schedules, runs, tasks and attempts. It distinguishes
resource/dependency/array/configuration/space waits from transient failures; waits
do not consume retries. Transient failures receive two retries at 60 and 300
seconds, using monotonic deadlines. Terminal summaries expire after 30 days or
1,000 runs; compact command receipts remain for the boot. Referenced attempt and
configuration artifacts are preserved until their journal evidence is pruned.

Auto Snapshot now submits through the coordinator from Run Now and cron. Workers
receive captured configuration in RAM and check the live revision before each
mutation. New elapsed schedules start one interval after Save. Legacy cron
alignment remains until conversion is selected; previews show that alignment.
Run Now does not move the anchor. The shared calculator supports five-field cron,
daily/weekly calendar schedules, repeated DST times and nonexistent local times.
Cancellation records its persistent fence and pause before signaling; the API
separates that acknowledgment from verified shutdown. Resume is explicit.

Snapshot Manager submission receives a stable run ID. Each granted attempt executes
at most 50 items. Status requests only read atomic manifests and deletion results;
they never republish state or start a worker. Failed-only retries retain the
five-minute review requirement. Deletion execution has its own coordinator-owned
process group, independent of each batch chunk. Dataset Migrator now holds the
same RAM dataset/ancestor gates as the other workers during normal and recovery
operations.

Send schedules now have versioned specifications in the existing configuration.
Existing jobs retain their actual local epoch-window alignment, including the
legacy Thursday-based seven-day window, until explicitly converted. New elapsed
intervals anchor at Save; new daily/weekly schedules use a chosen local start time
and weekday. The Send page uses the shared preview endpoint. Calendar activation
prevents a new schedule from catching up an occurrence before it was saved.
Actual endpoint tests verify unchanged calendar saves retain their time and anchor,
and invalid times do not change configuration. The existing Bash scheduler reads
these specs through the same PHP occurrence calculator during admission.

The legacy send scheduler now records accepted occurrences separately from
success in RAM. Exhausted failures remain visible but do not block later
occurrences. Clearing a failed display record does not recreate that occurrence.

## Flash-write inventory

| Path | Purpose and permitted writers |
| --- | --- |
| `/boot/config/plugins/zfs.autosnapshot/zfs_autosnapshot.conf`, `zfs_send.conf` | Explicit atomic configuration saves, installation defaults |
| `/boot/config/plugins/zfs.autosnapshot/send-prefix-history` | Explicit configuration saves; unchanged content is skipped |
| `/boot/config/plugins/zfs.autosnapshot/send-control` | Explicit Cancel/Resume fences and pauses |
| `/boot/config/plugins/zfs.autosnapshot/dataset_migrator/recovery.env` | Safety-transition checkpoints only; source/target identities and container restoration obligations |
| Legacy flash runtime directories | Installation-only quarantine/migration; never automatic replay |
| `/tmp/zfs-autosnapshot-ops` | Job records, accepted/completed cursors, deletion results, reviewed batches |
| `/tmp/zfs-autosnapshot-coordinator` | Journal, command receipts, attempt grants and captured configuration |
| `/tmp/zfs-autosnapshot-migrator` | Migrator status, progress, folder/container display state |
| `/var/run/zfs-autosnapshot-*`, `/tmp/zfs-autosnapshot-config-locks` | Ownership, locks and socket |
| `/var/log/zfs_autosnapshot*`, `/var/log/zfs-autosnapshot-failed-sends` | Bounded routine logs and failure captures |

There is no pool-backed state directory or periodic RAM-to-flash checkpoint.

## Additional verification

- Full stage-one and reliability suites passed after coordinator integration.
- Actual batch endpoints verify a 601-item selection requires at least 13 granted
  attempts, duplicate submissions retain a run ID, and polling does not replace
  the manifest inode. Partial failure, failed-only retry and exact deletes pass.
- Actual daemon tests cover captured configuration, committed cancellation,
  verified shutdown, complete RAM loss, persistent pause, explicit Resume, and
  cancellation while a configuration-save lock is held.
- Socket/executor tests cover partial requests, duplicate/conflicting commands,
  SIGKILL recovery, surviving pipeline children, interrupted checkpoint
  publication, corrupt checkpoint rejection and retention of active evidence.
- Schedule tests cover seven-minute/five-hour intervals, Save anchors, catch-up,
  legacy alignment, retry exhaustion acceptance and calendar DST gaps/folds.
- `coordinator_flash.php` passed with `/boot` mounted read-only. A syscall trace
  found zero attempted boot-flash writes or metadata changes during daemon
  launch, completion, duplicate submission, polling and batch admission. This
  extends the earlier runtime trace; it does not certify every transfer path.
- Both Chromium suites, PHP parsing and ShellCheck error-level checks passed.
- Real disposable pools passed full/incremental sends, cancellation and explicit
  resume. An added quota-shortage fixture used the production reference exclusion
  predicate before cleanup, preserved the incremental base, reclaimed sufficient
  measured space, completed the transfer and preserved unrelated snapshots. This
  is not yet an end-to-end test of the new coordinator's replication phases.

## Remaining plan scope — not release complete

Replication creation, preparation, fan-out, retries and finalization still use
the Bash queue handler. The coordinator is therefore **not yet the sole authority
for all work**. Deletion's internal queue/result transitions still reside in its
existing worker. Batch cancellation is not exposed through the Auto Snapshot
Cancel API. Complete send integration must preserve existing GUID, hold, clone,
resume-target, destination and pipeline protections.

Automatic re-planning after a configuration revision change,
ZFS-proven reboot completion suppression, complete dependency/recovery status
fields, and comprehensive idle-scan, timezone-change and all-path flash-write
instrumentation also remain. The shared calculator alone does not complete these
production behaviors.

After reboot, RAM history, batch approval and manual-send authority are gone.
Manual interrupted transfers require explicit Retry; batches require a fresh
review and previous per-item results may be unavailable. Persistent pauses and
migration safety checkpoints survive. Exactly-once execution across a reboot is
not promised.
