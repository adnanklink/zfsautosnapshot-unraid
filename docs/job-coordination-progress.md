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

Re-planning replication and already-attempted automatic work after a configuration revision change,
ZFS-proven reboot completion suppression, complete dependency/recovery status
fields, and comprehensive idle-scan, timezone-change and all-path flash-write
instrumentation also remain. The shared calculator alone does not complete these
production behaviors.

After reboot, RAM history, batch approval and manual-send authority are gone.
Manual interrupted transfers require explicit Retry; batches require a fresh
review and previous per-item results may be unavailable. Persistent pauses and
migration safety checkpoints survive. Exactly-once execution across a reboot is
not promised.

## Follow-up: Auto Snapshot configuration admission (source only)

Untouched queued automatic runs now adopt an atomically read configuration pair
before launch. The RAM journal records the replacement revision and original
revision while preserving the run ID, command receipt and accepted occurrence.
Admission uses the shared nonblocking configuration lock, so an in-progress save
cannot supply mixed settings or stall cancellation. Configuration capture is
published by atomic rename and repairs partial captures left by an interrupted
older publication.

Manual requests never acquire new approval implicitly. Runs with any previous
attempt, legacy records without the captured schedule specification, disabled or
converted schedules, and empty dataset selections fail configuration admission
without starting a worker or consuming a transient retry. In particular, a new
Save anchor does not cause an old queued occurrence to run immediately. Workers
already running retain their captured settings and existing revision checks
before each mutation. Safe replanning after partial execution still requires
per-item completion evidence and remains outstanding.

`coordinator_replan.php` covers stable identity/acceptance, restart, capture repair,
manual approval, recovered attempts, rejected siblings and schedule changes.
`coordinator_replan_daemon.php` exercises the production daemon with a harmless
worker: execution once with new settings, rejection of stale manual authority,
and responsive status during a configuration lock. Run the latter in its own
disposable container because it writes production-style fixture paths.
The full stage-one and reliability suites, existing Auto Snapshot daemon test,
read-only-boot coordinator fixture, PHP lint, changed-shell checks and temporary
package inventory verification pass. These changes are not in the published
`2026.09.16.01` package; release artifacts are unchanged.

## Follow-up: fixed replication manifests and deletion ownership (source only)

Replication preparation now commits a versioned membership manifest to RAM before
publishing its first child. It captures source datasets, destinations, selected
snapshot names, snapshot GUIDs and source dataset GUIDs. Recovery reuses that
membership instead of enumerating a changed recursive tree or filtering a new
set of missing resume members. Existing successful children are retained only
when their immutable transfer identity matches. Finalizers carry expected child
identity digests and require explicit matching success for every child. The
source dataset GUID is also checked before transfer. Selected members whose
child files have not yet been published remain protected from cleanup.

Legacy pending finalizers without identity evidence fail validation; they do not
mark an occurrence complete merely because positional child IDs exist. Existing
successful history is not rewritten. This does not yet solve the earlier crash
window between ZFS snapshot creation and publication of the preparation record,
and it does not implement automatic replanning after partially completed work.
The Bash queue handler still owns replication admission and retries.

Replication cleanup now uses the coordinator deletion submission path. The Bash
helper no longer falls back to an untracked worker when the coordinator is
unavailable; queued requests remain in RAM. After verified deletion-worker
shutdown, the coordinator checks the inbox, interrupted processing files and
queued state before completing the pump. Late submissions coalesced into an
exiting run therefore receive another granted attempt without consuming a
transient retry. Deletion's individual queue transitions remain worker-owned.
The coordinator also skips unchanged batch-manifest publication after worker
completion, fixing an inode-change race observed by the read-only polling test.

Verification: the full reliability and stage-one suites pass. `send_manifest.sh`
interrupts fan-out after one child, changes the discovered dataset tree, preserves
successful evidence, rejects changed GUIDs and verifies explicit zero-child
resume finalization. It passes with `/boot` mounted read-only. Run
`coordinator_delete.php` in a separate disposable container; its production daemon
fixture verifies late enqueue, one run with two verified attempts, and stranded
inbox/queued-state recovery. Actual 601-item batch endpoint tests, the existing
read-only-boot coordinator fixture, ShellCheck, PHP/Bash checks and temporary
package verification pass. Disposable real-ZFS full/incremental, cancel/resume,
low-space cleanup, base and unrelated-snapshot preservation tests pass; no test
pools remained. The real-ZFS suite does not yet exercise full coordinator-owned
replication phases. No release artifacts or installation were changed.

## Development package 2026.09.16.02

This package includes the source-only follow-ups recorded above: automatic
configuration admission, fixed replication manifests, coordinator-owned cleanup
launches and late deletion submission recovery. Both manifests retain the fork
branch pluginURL so existing branch clients can discover the higher version.
Full replication coordination and replanning after partial execution remain
unfinished. Release artifacts are committed separately from source and docs.

## Coordinator completion branch: recovery boundaries (2026-09-17)

Work continues on `fix/coordinator-completion`, based on `feat/ui-overhaul`.
This is source work for the planned complete release; it does not change the
published version, install manifests, packages, or update channels.

The RAM journal now uses protocol version 2 and accepts version 1 checkpoints
from the same boot. Each granted attempt carries the coordinator generation,
task ID and attempt token. Workers can propose bounded progress, explicit results
and an atomic child graph over the Unix socket. Ordered report sequences reject
conflicting or stale publications, while replaying the last accepted report is
idempotent. Preparation graphs require a finalizer that depends on every child.
Reported completion never releases resources before verified group shutdown.
These capabilities are tested, but replication adapters are not yet migrated to
this protocol.

Scheduled replication now publishes exact snapshot creation targets and source
dataset GUIDs in RAM before invoking ZFS. Explicit targets freeze recursive
membership. Snapshot GUIDs are committed before child publication. If execution
is interrupted without those GUIDs, existing targets are preserved and require
review. The intent retains cleanup protection even if the create command exits
with an error and retries are exhausted. Successful GUID publication clears the
recovery flag. Explicitly clearing an ambiguous recovery record warns that its
cleanup protection will be released. Intent publication failure prevents creation.

Snapshot Manager publishes an item intent before execution and its result
immediately after execution, rather than deferring all results until chunk end.
An interrupted non-delete item becomes a failed item requiring a fresh review;
rollback is never automatically repeated. Deletion continues to reconcile its
stable ID and existing result record. Failed-only retry still creates a new
review, and previously completed items are retained. Recovery flags appear in
batch and coordinator status. These records remain RAM-only and disappear on
reboot; no old approval is reconstructed.

Status reads and rejected socket requests no longer force an admission tick.
Accepted mutation commands and the watchdog still wake the coordinator, with
the existing maximum 30-second recovery deadline.

Verification for this increment:

- Stage-one and full reliability suites passed in disposable containers.
- Actual 601-item batch endpoints passed, including bounded chunks, partial
  failures, failed-only retries, approval expiry and exact deletion boundaries.
- A SIGKILL fixture interrupts a batch after the mutation and before result
  publication, verifies review is required, and verifies no repeated mutation.
- Real granted fixture workers exercised progress, duplicate reports, child graph
  publication, explicit child failure, finalizer dependencies and stale rejection
  through the production Unix-socket client and executor.
- Actual Auto Snapshot daemon checks passed for captured settings, cancellation,
  verified shutdown, RAM loss and persistent pause/Resume.
- New intent/recovery fixtures and the coordinator flash fixture passed with
  `/boot` read-only. A file-syscall trace recorded 199 `/boot` accesses and zero
  attempted writes or metadata changes. This remains scoped fixture coverage,
  not certification of all transfer paths.

- Disposable real-ZFS pools passed the new exact-target creation/GUID fixture,
  fixed membership after a child dataset is added, and the existing full/incremental,
  cancel/resume, shortage cleanup and unrelated-snapshot preservation checks.
  A follow-up `zpool list` found no remaining test pools.
- Chromium workspace checks passed across all sections, desktop/tablet/mobile,
  recovery presentation, and dismissal/acceptance of the clear-protection warning.
- A package built only under `/tmp` matched the source inventory. No release
  artifact was added to the repository.

The remaining-plan section above still applies. In particular, individual
replication/deletion transitions, batch cancellation across deletion children,
partial-execution replanning, conservative reboot completion proof, full-path
flash tracing and coordinator-driven real-ZFS acceptance remain unfinished.
User-run Unraid checks remain the final release gate. Publish one completed main
release afterward, with update manifests for existing fork preview clients.

## Ownership update: journal and admission foundation

The approved ownership update is being implemented on `fix/coordinator-completion`.
The first increment introduces journal version 3: checksummed, sequenced RAM
append records with atomic checkpoints after 1,024 records or 8 MiB. The checkpoint
publishes before covered log records are removed. Recovery discards only an
incomplete trailing append, rejects complete corrupt records and sequence gaps,
and preserves accepted commands across interrupted compaction. Diagnostic readers
replay the log and retry if checkpoint/log replacement races their read.

Version 1/2 coordinator checkpoints remain readable. Pending manual runs are
marked as requiring fresh approval during this ownership upgrade. Completed
results remain intact, and active attempts retain ownership until shutdown is
verified. This does not invalidate approvals on ordinary version 3 restarts.
The complete legacy send/batch installation handoff remains to be implemented.

Admission now maintains ready tasks, reverse dependencies, active tasks and a
monotonic deadline heap in memory. The executor checks active attempts rather
than repeatedly traversing historical attempts and tasks. Indexes rebuild from
the journal after restart and invalidate canceled work and obsolete deadlines.
Journal delta detection still traverses entity records at publication; moving
large per-item execution onto this journal will also require measuring that cost.

Preparation plans can be staged in chunks of at most 50 task specifications.
Each chunk is ordered, checksummed through the journal and replay-safe. A final
seal checks the complete count, digest, dependency graph and required finalizer
before publishing executable child tasks. Sealing supports up to 50,001 tasks
(50,000 items plus finalizer). The parent cannot report success before sealing,
and children still wait for verified parent shutdown. The existing single-message
protocol remains supported for small plans.

Verification: full reliability suite; actual 601-item batch endpoints; actual
Auto Snapshot and deletion-daemon fixtures; PHP parsing; new torn-append,
compaction, sequence-gap, manual-upgrade and index-rebuild fixtures. A 10,000-task
fixture verifies dependency/deadline/cancellation indexing. A 1,101-child staged
plan verifies chunk replay, partial-plan exclusion and sealing after restart.
The new storage/index/plan fixtures and coordinator runtime fixture also pass
with read-only `/boot`. These checks do not certify all-path flash-write behavior.

Remaining implementation: per-item authorization/results in the coordinator,
unified deletion and Auto Snapshot mutation admission, full replication phases
and scheduling, Snapshot Manager execution ownership and shared cancellation,
installation handoff, compatibility/status migration and full acceptance gates.
No release artifact, update URL or published version changes in this increment.

## Ownership update: approved batch item authority

Non-delete Snapshot Manager execution now uses coordinator-owned item records.
Submission captures immutable approved item specifications. The worker requests
at most 50 items from the journal, receives an acknowledged start grant for one
item at a time, and reports a bounded result before starting the next item. Grant
membership and fingerprints prevent expanding or changing the reviewed selection.
Repeated report acknowledgments return the same response without new authority.
The old batch worker refuses non-delete execution; older batch tasks without item
authority require a new review rather than falling back to worker-owned state.

The coordinator projects execution results into the existing RAM batch manifest
for endpoint compatibility. The execution worker does not publish that manifest.
Projection runs after item reports and verified process transitions. Existing
selection capture and review remain in the endpoint. Deletion batches still use
the legacy deletion adapter and are not yet covered by this ownership handoff.

On verified shutdown, an item started without a committed result becomes failed
and recovery-required. Completed items remain completed and untouched items can
continue in another grant. An item report does not release process ownership.
Ambiguous item evidence and its batch projection are exempt from ordinary terminal
retention while review remains unresolved. No extra flash writes are introduced.

Verification: full reliability suite and the actual 601-item endpoint suite pass.
New item-state fixtures cover chunk limits, immutable membership, start response
replay, serial execution, committed results, stale/canceled reports, recovery and
retention. `coordinator_batch_recovery.php`, run in its own disposable container,
uses the actual daemon and new worker, kills the coordinator while a simulated
ZFS mutation is active, verifies surviving-process shutdown, preserves the
ambiguous item for review, and completes the untouched item exactly once. PHP
parsing and the item/coordinator runtime fixtures with read-only `/boot` pass.

Still required: unified deletion execution, Auto Snapshot mutation authority,
full replication phases and scheduling, shared cancellation, complete legacy
installation handoff, configuration replanning and the remaining release gates.

## Ownership update: single-deletion adapter foundation

A granted single-deletion adapter now reuses the existing worker's safety checks
without loading or flushing its internal queue. It verifies coordinator ownership
through the worker socket before processing, takes the global deletion lock,
returns array/resource waits immediately, and reports an explicit result. Local
and remote destroy execution make one attempt; transient retries belong to the
coordinator. It never publishes authoritative deletion result files.

`coordinator_delete_adapter.php` uses the real socket, executor and adapter with
mock ZFS. It verifies exact deletion, changed-GUID and held-snapshot exclusion,
explicit outcomes, one failed destroy per grant and coordinator retry scheduling.
Run it in a disposable container with the plugin and sbin source mounted at their
production paths. ShellCheck error-level and readiness safety checks pass.

This adapter is not yet selected by the production daemon. Deletion submission
import, individual task/result projections and shared run ownership must be wired
before replacing the existing deletion pump. The legacy worker remains executable
and can now also be sourced by the adapter without starting its daemon loop.
