# Replication and Snapshot Manager reliability audit

Branch: `fix/send-cancellation`. This document covers source changes, not deployment.

## Findings and disposition

| Finding | Evidence in original paths | Disposition and verification |
| --- | --- | --- |
| Cancellation could race stale writes and late fan-out | PHP cancellation and Bash job writers did not share a durable decision fence | Persistent run tombstone and schedule pause precede signals; shared lifecycle lock and revision comparisons reject stale publications. Cancellation regression and real ZFS cancellation pass. |
| A worker leader could exit while pipeline children survived | PID-only liveness checks released ownership | Isolated process groups and start-time verification; stop all surviving group members before recovery or resource release. Ownership test kills a real leader and verifies child shutdown and reservation retention. |
| Finalization could lose child-success evidence | Timed pruning and PHP status-reader pruning | Only manager prunes; retain child records until finalizer completes; require every expected deterministic child ID to be explicitly complete. Missing, skipped and running child regressions pass. |
| Destructive destination fallback | Recursive destination destruction and forced receive rollback | Removed automatic reseed and `receive -F`; GUID checks for source, destination and common base; matching resume target required. Real unrelated destination contents survive rejected full and incremental receives. |
| Queued work could use changed connection settings | Daemon loaded connection configuration once | Fingerprint send configuration in queued send/delete jobs; reload and compare at execution. Changed/legacy unbound automatic cleanup is skipped for replanning. Manual batches also bind to both configuration revisions. |
| Send/delete/rollback and automatic cleanup could overlap | Independent workers and active-marker timing | Shared/exclusive dataset ancestor locks and shared automatic-cleanup gate; automatic cleanup holds exclusive gate or skips cleanup; pending transfers protect their trees. Rollback uses no recursive flag and rejects newer snapshots. |
| Delete selection expanded into checkpoint trees | Legacy checkpoint scope in ordinary delete | Ordinary deletion enqueues only selected names/GUIDs. Legacy tree jobs fail closed. Integration verifies an unselected adjacent snapshot remains. |
| Delete publication versus exit lost work | Inbox append and daemon teardown used independent ownership | Permanent daemon flock, publication of drained state before removing processing input, replay of stranded inputs, and exit/inbox locking. Integration includes enqueue after idle exit. |
| Detached workers inherited locks | New batch-to-delete integration repeatedly reported dataset busy after the batch worker exited | Added descriptor-clearing detached launcher; release batch dataset locks before daemon launch. Real daemon integration and inherited-flock regression pass. |
| Immediate retry saw a completed failure as pending | Result journal published before throttled queue snapshot | Terminal result journal overrides stale pending rows; reconcile manifests when calculating pending actions. Extended endpoint test covers immediate failed-delete retry. |
| Migration launch destroyed another worker's state | PHP reset runtime before worker ownership; lock loser could write status | Worker owns flock before resets/log maintenance; rejected workers leave status/folders/containers/log intact. Status startup response checks dataset and PID. Lock-loser and existing recovery simulation pass. |
| Prefix validation could omit counterpart | Send endpoint omitted Auto Snapshot prefix | Shared save service reads both authoritative configs under one lock. Equality, both overlap directions, safe stems, existing conflict repair, missing revision and simultaneous saves tested through actual endpoints. |
| Settings page could bind stale values to a newer revision | Config values and revision read separately | Shared-lock page snapshot binds both configs and revision. Atomic writes, serialized cron application, distinct saved/scheduler result, and prefix history preserve checkpoint protection. |
| Bulk UI could add new snapshots or accept stale responses | Full-list refresh and selection keyed only to display | Fixed name/GUID identities, generation/dataset guards, captured matching selection, server pagination, bounded visibility-aware polling. Chromium and 10,000-row fixtures pass. |
| Cleanup preview could act on changed snapshots | No approved identity manifest | Pure preview; five-minute expiry; configured revision, exact GUID and eligibility checked at execution and shared delete boundary. Holds/clones/anchors/checkpoints/pending actions/incomplete metadata exclude candidates. |
| Batch review accidentally ignored other batches' pending work | Clearing all pending flags in worker revalidation | Ignore only the current token and deterministic delete job ID; retain other pending exclusions. |
| Upgrade cleared queue state and lock inodes without confirmed shutdown | `post-install.sh` removed runtime queue and job locks; stop tree did not verify KILL completion | Preserve queue records and permanent locks; start-time-bound shutdown, complete-group recovery, PHP worker recognition and maintenance gate. Source-only proposal documented separately; no installation run on development host. |
| Old stream URLs held PHP workers | Long/infinite stream loops | Browser uses bounded polling; compatibility stream URLs emit one event and close. Embedded Snapshot Manager pauses when its containing tab is hidden. |

## Validation

- Full existing `tests/stage1/run.sh`: passed, including low-space policies, migration recovery simulation, endpoint contracts, transport commands, and latest-common checkpoint protection.
- `tests/reliability/run.sh`: passed; cancellation, ownership/finalization/orphan recovery, destination identity, actual settings endpoints, 10,000-row inventory/cleanup fixtures, and selection state.
- `tests/reliability/browser.cjs`: passed in Chromium; 10,000 snapshots, disabled Shift ranges, cross-page selection, fixed matching selection, 500-item upload chunks, filters, and delayed dataset responses.
- `tests/reliability/config_browser.cjs`: passed on actual PHP-rendered settings pages; asynchronous discovery, prefix conflicts, reset defaults and preserved settings, dirty indicators.
- `tests/reliability/batch_endpoints.php`: actual PHP endpoints, PHP batch workers and Bash shared delete daemon with fake ZFS. Covers 601-item manifests, explicit-list limit, review without mutations, duplicate submissions, partial failure, failed-only retries, GUID changes, expiry, held cleanup, exact selected deletion and daemon restart.
- `tests/reliability/zfs_integration.sh`: passed using OpenZFS CLI 2.1.11 and kernel module 2.2.2. Two unique 512 MiB file-backed pools; full and incremental sends; unrelated destination preservation; real throttled pipeline canceled through PHP service; durable pause/tombstone; wrong resume target rejected; explicit Resume and byte comparison. Pools destroyed and a read-only check found no remaining test pools.
- PHP lint, Bash parsing and ShellCheck error-level checks passed across packaged source. Browser tests also parse and execute the new JavaScript.
- Package verification uses a temporary `.txz` and compares its file inventory with `source`; no generated release artifacts are committed.

The low-space fixture originally printed updated large byte counts in scientific notation under Debian awk. Its integer output is now explicit. Transport fixtures supply snapshot GUIDs to exercise the added identity checks.

## Reproduction

Use a disposable PHP 8.3/Debian container with Bash, Python, Node 20+, Chromium, Playwright Core, ShellCheck and procps. Mount the repository read-only at `/work` and use that working directory. Endpoint tests write production-style paths **inside the container**, so never run those fixtures on an installed Unraid host.

Run stage-one, `tests/reliability/run.sh`, and the two `.cjs` browser suites. Run `batch_endpoints.php` in a separate container with the plugin source additionally mounted read-only at `/usr/local/emhttp/plugins/zfs.autosnapshot` and sbin source at `/usr/local/sbin`; set `DELETE_QUEUE_IDLE_TIMEOUT_SECONDS=1`.

The optional real-ZFS script additionally requires `/dev/zfs`, mount capability and `ZFSAS_DISPOSABLE_POOL_TEST=1`. File vdevs must be under a dedicated host-visible temporary directory mounted at the same absolute path; pass that directory as `ZFSAS_POOL_FIXTURE_ROOT`. The script creates uniquely named `zfsas_test_*` pools and checks their recorded GUIDs before destroying them. Do not grant it access to a production pool fixture.

## Limits and operational notes

- The real-pool test exercises local transfer/cancel/resume. SSH command construction, pinned host keys, token handling and remote metadata are covered by mocks, not a real remote Unraid receiver. spiped remains disabled for replication.
- Dataset ancestor locking includes the pool ancestor. This deliberately favors safety and can serialize unrelated mutations within a pool. Sends retain shared locks.
- Runtime inventories and batch manifests live under `/tmp`. They survive plugin upgrades but not host reboot. Persistent cancellation and schedule pause live under `/boot`. A batch delete recovered without its approval manifest fails closed; create a fresh review after reboot.
- Historical send prefixes are conservatively protected. Resolving a prefix conflict does not rename old snapshots or release that protection automatically.
- Plugin locks coordinate plugin actions. External ZFS commands do not honor those locks; GUID and metadata checks run immediately before actions, but ZFS does not offer an atomic “destroy only if GUID equals” command.
- Cleanup defaults remain per dataset. Pool-wide low-space cleanup is intentionally not offered by the preview UI. Written-byte totals are never presented as guaranteed reclaimable space.
- Legacy `.op` requests are retained and never automatically executed by the replacement worker. Re-select those actions to create a current reviewed manifest.
- Upgrade shutdown verification was tested with disposable processes. Actual plugin installation, service restart and deployment are outside these source changes.


## Job coordination branch

The subsequent `fix/job-coordination` implementation and its scoped verification
are tracked in [Job coordination implementation record](job-coordination-progress.md).
That record includes the flash-write inventory, schedule migration behavior and
explicit remaining work. The branch is not yet a complete replacement of the
replication queue handler. Run `coordinator_auto.php` separately in a disposable
container because it installs a fake execution worker at a production path.
`coordinator_flash.php` requires a read-only `/boot` fixture with an Auto Snapshot
dataset configured and scheduling disabled; it does not write configuration.

Auto Snapshot now replans untouched scheduled work at admission after a coherent
configuration read. Changed manual approval and previously attempted work fail
validation without launching a worker; schedule conversion cannot bypass the new
first-run time. `coordinator_replan.php` runs in the reliability suite. Run
`coordinator_replan_daemon.php` separately in a disposable container, like
`coordinator_auto.php`. Replanning after partial execution remains unimplemented.

Replication follow-up: `send_manifest.sh` now reproduces interrupted child
publication and a changed recursive dataset inventory. Frozen membership and
GUID-bound finalizer evidence prevent a previous successful child from being
accepted for a different transfer. Legacy pending finalizers lacking that evidence
fail validation. Full replication coordination remains outstanding.

Run `coordinator_delete.php` in its own disposable container. It submits deletion
work during worker exit and verifies a second granted attempt in the same run,
without consuming a failure retry. Replication cleanup now uses this coordinator
launch path. The batch endpoint regression also caught and verified a fix for
unnecessary unchanged manifest publication after worker completion.

## UI preview verification (2026-09-17)

The `feat/ui-overhaul` branch adds a shared shell and read-only activity/summary interfaces. See [UI implementation and tests](ui-overhaul.md). Stage-one and backend reliability fixtures continue to pass after separating controllers, views and browser behavior. PHP endpoint checks run with `/boot` read-only; the new views and runtime summary do not initialize persistent directories. A `strace -f -e trace=%file` run of `workspace_endpoints.php` recorded zero attempted `/boot` writes or metadata changes. Migrator polling now has a RAM-only runtime mode, with dataset and Docker inspection reserved for explicit preview.

The UI does not close the remaining replication coordinator/reboot-reconstruction work. Real-ZFS execution coverage recorded above belongs to the underlying coordination work; this presentation change retains those execution workers. Actual Unraid theme integration still requires a host smoke test.

## Recovery boundary follow-up (2026-09-17)

The completion branch adds RAM creation intent before replication snapshots and
per-item intent/results for Snapshot Manager. Interrupted creation without a
committed GUID manifest preserves exact targets for review. Interrupted batch
mutations cannot be silently replayed; failed-only retries require a new review.
See [implementation record](job-coordination-progress.md#coordinator-completion-branch-recovery-boundaries-2026-09-17)
for verification and remaining release gates.

The version 2 worker protocol fences generation, attempt and report sequence.
Its socket fixture exercises real granted workers and finalization dependencies.
Read-only status polls and rejected requests no longer wake admission scans.
The new crash fixtures and coordinator flash fixture pass with read-only `/boot`;
a syscall trace found zero attempted flash writes or metadata changes. Production
replication still uses the legacy queue handler, so this is not yet full
coordinator integration or all-path flash-write certification.

## Individual deletion ownership follow-up

Production deletion submissions now become coordinator tasks with captured GUIDs,
configuration identity and batch ownership where applicable. The PHP coordinator
owns retry deadlines, import receipts and result publication after shutdown. The
old deletion executable wakes admission; it no longer starts the queue daemon.
Legacy queue displays are preserved in RAM for review rather than replayed.

The deletion importer fixture covers interrupted cursor replay, identity conflicts,
legacy authority rejection, revoked batch ownership and late submissions. It also
passes with read-only `/boot`. The granted adapter fixture verifies hold/GUID
checks and single-attempt failure reporting. Retention fixtures preserve completed
children of unfinished owners while removing expired orphaned RAM artifacts.
Stage-one, reliability, syntax/ShellCheck and temporary package verification pass.
The actual batch endpoint suite also passes the full 60/300-second retry sequence,
failed-only retry, daemon restart and exact deletion checks.
These checks do not replace the outstanding all-path flash trace, real-ZFS
coordinator pipeline tests or complete installation handoff. Shared cleanup
ownership and deletion-batch item authority remain open.

Deletion-batch recovery now validates live attempt ownership before worker locks
and before each item submission. The handoff fixture verifies rejection of missing
or expired grants over the real socket, acceptance of current ownership, fresh
review for unresolved legacy deletions and preservation of committed results; it
passes with read-only `/boot`. Coordinator crash recovery also defers unrelated
new grants until every surviving old process group has stopped. The full
reliability suite covers this cross-task recovery barrier. Journal ownership of
all deletion-batch item transitions remains unfinished.

Deletion batches now delegate immutable journal items to at most 50 concurrent
pending deletion dependencies and finalize from verified results. The production
daemon no longer launches the manifest-owned deletion batch worker. New fixtures
cover interrupted delegation, bounded replay, partial outcomes, rejection of inbox
approval bypass and reconstruction of contended/lost status projections. Read-only
flash, reliability, stage-one, PHP, package and actual batch endpoint checks pass.
Shared cancellation, full replication ownership and the final scale/host gates
remain outstanding.

Batch cancellation now propagates to exclusively owned deletion runs, preserves
unrelated work and reports shutdown completion only after children stop. Activity
routes batch Cancel to the coordinator. Fixtures cover live and queued children,
51-item membership, persistent-write failure and retry, unchanged repeated control
decisions, no unrelated schedule pause and restart. Workspace browser coverage
checks the request route and batch confirmation. Shared cleanup cancellation with
multiple owners remains a separate, unfinished acceptance gate.

Deletion approval is now captured from immutable journal items into
`/tmp/zfs-autosnapshot-coordinator/attempt-inputs/*.job.approval.json`. These RAM
artifacts contain the bound task/job identity, batch configuration and exact
selected item; no new flash runtime writes are introduced. The deletion checker
uses the capture, while status manifests and result files remain projections.
Tests reject mismatched/missing captures and prove that stale compatibility results
cannot fabricate completion. Capture retention follows journal task retention.
Run `deletion_approval.php` in its own disposable container (also supported with
read-only `/boot`); it deliberately creates fixture paths and a ZFS command stub.

A `strace -f -e trace=%file` run of the isolated approval fixture with read-only
`/boot` recorded 2,922 filesystem calls, 92 boot-path references and zero attempted
boot writes or metadata mutations. This is scoped approval-path evidence, not the
outstanding all-path flash-write certification.

Coordinator reference registration now requires exact dataset and snapshot GUIDs,
name, endpoint and reference role. It commits with preparation plans, rejects
self-destructive cleanup graphs and races with active deletion attempts, and gates
production deletion admission without launching waiting workers. Reference indexes
cover 10,000-record fixtures and rebuild on restart. Tests preserve references
through finalization/recovery and retain another owner's protection on cancellation.
Read-only `/boot` fixtures pass. Legacy send planners have not yet switched to the
new registry; this is not full replication integration or shared cleanup authority.

## Standalone native inspection

The new preparation worker performs bounded read-only local receiver inspection
under a coordinator grant. Commands remain in the owned process group; timeout
and cancellation fixtures use the actual socket/executor with `/boot` read-only.
Inspection unit tests cover 10,000 snapshots, GUID matching, 64-bit ordering,
identity races and explicit resume recovery without exposing tokens. The
real-ZFS disposable-pool suite now asserts native GUID-matched base selection.
The phase does not grant transfer permission; SSH, new receivers, complete native
planning/transfer/finalization and shared cleanup ownership remain outstanding.
Standalone packaging will use a new identity once a name is chosen; legacy queue
migration is no longer a release requirement.

### ZFS SnapSync identity verification

The standalone source uses `zfs.snapsync` and separate snapshot-prefix/hold defaults.
Reliability, stage-one, cancellation endpoint, inspection worker, browser, PHP lint,
ShellCheck error-level checks and a temporary package build pass after renaming.
Disposable-pool transfer, cancellation, explicit recovery and low-space cleanup
regressions also pass. Package verification now rejects original-plugin paths and
checks the standalone update identity. No new release was published; existing
tracked manifests remain historical original-identity artifacts. These checks do
not certify concurrent old/new schedulers on the same datasets or completion of
the native replication pipeline.

### Native receiver preparation boundary

Preparation now rejects divergent receiver histories and recognizes completed
snapshots only from matching GUID evidence rechecked after inventory. The full
reliability suite and granted worker timeout/cancellation tests pass. Disposable
ZFS fixtures verify the completed-target decision and reject a new transfer past
an unrelated receiver snapshot while preserving that snapshot. Inspection output
is not execution authority or proof of available space; native downstream phase
integration remains open.

### Recursive native scheduled execution

The actual coordinator completed a three-level local recursive graph on disposable
ZFS pools, including exact member GUID checks and metadata-proven snapshot reuse.
Unit fixtures cover 10,000 captured members, missing/changed membership, atomic
snapshot intent, bounded plan sealing, nested completion dependencies across restart,
and source-reference publication before acknowledgment. Browser, PHP, ShellCheck
and temporary package checks pass. This does not certify replacement of automatic
Send scheduling: native retention/space cleanup and timer admission remain open.

### Native cleanup before space admission

Disposable-pool native recursive replication now covers a measured quota shortage
resolved by coordinator-owned retention deletion before space approval, with the
incremental base preserved. All required children complete explicit verification.
The same scheduled execution passes with `/boot` mounted read-only in the isolated
container. Retention fixtures cover exact prefix scope, newest/base/shared-GUID
protection, holds, clones and replacement identities; space fixtures cover configured
headroom, incomplete metadata, overflow and measured freeing waits. Full-path
syscall tracing and shared cleanup ownership remain outstanding.
