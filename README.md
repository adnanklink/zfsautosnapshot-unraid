# ZFS Auto Snapshot for Unraid

ZFS Auto Snapshot is an Unraid plugin for managing ZFS snapshots from the WebGUI. You choose the datasets, set the retention rules, and decide whether it runs on a schedule or only when you press Run Now.

The plugin also includes ZFS Send replication, a Dataset Migrator, Snapshot Manager bulk and cleanup tools, and a diagnostics download for support.

## What it does

- Creates snapshots for the ZFS datasets you select.
- Cleans up old plugin-created snapshots using keep-all, daily, and weekly retention windows.
- Watches pool free space and can prune older eligible snapshots before a run when space gets low.
- Lets you preview a run with Dry Run mode before allowing snapshot changes.
- Shows run output and debug logs in the WebGUI.
- Replicates datasets with ZFS Send using separate send checkpoint snapshots.
- Provides a redacted diagnostics zip for GitHub issues.

The plugin only manages snapshots that match its configured snapshot prefix. By default that prefix is `autosnapshot-`.

## Install

Use this plugin URL in Unraid:

```text
https://raw.githubusercontent.com/bstone108/zfsautosnapshot-unraid/main/dist/zfs.autosnapshot.plg
```

Minimum Unraid version: `6.12.0`, because that is the first Unraid release series with native ZFS pool support.

After install, open:

```text
Settings -> ZFS Auto Snapshot
```

## First setup

The plugin starts safe: no datasets are selected and the schedule is disabled until you save your own settings.

Basic setup:

1. Select the datasets you want the plugin to manage.
2. Set a free-space target for each dataset's pool, such as `100G` or `2T`.
3. Check the snapshot prefix. The default is usually fine.
4. Choose your retention windows.
5. Choose a schedule, or leave it disabled and use Run Now.
6. Save settings.

If you want to see what would happen first, turn on Dry Run mode and start a manual run. Dry Run logs the planned actions without creating or deleting snapshots.

## Retention and free-space cleanup

Retention has three normal windows:

- Keep every snapshot for the newest period.
- After that, keep one snapshot per day.
- After that, keep one snapshot per week.

Anything older than the weekly window is eligible for cleanup, as long as it was created with the configured snapshot prefix.

The default example config uses:

- keep all snapshots for 14 days
- keep daily snapshots until 30 days
- keep weekly snapshots until 183 days

You can change those values in the WebGUI.

Free-space cleanup is separate from normal age-based retention. Each selected dataset can have a pool free-space target. If a pool is below that target before a run, the plugin looks for eligible old snapshots that can free space on that same pool.

That does not always mean it deletes from only the dataset that showed the warning. If several selected datasets share the same storage pool, or share quota space in a way where deleting a snapshot from one can free space for another, the plugin may prune the older eligible snapshot from the other dataset first. The goal is to free space safely while keeping the newest useful snapshots.

Unselected datasets are not part of automatic cleanup.

## Scheduling

You do not have to write cron by hand unless you want to.

The WebGUI supports:

- disabled / manual only
- every N minutes
- every N hours
- daily at a chosen time
- weekly on a chosen day and time
- custom cron for advanced use

When you save settings, the plugin writes the cron entry for you.

## Running manually

Use the Run Now button in the WebGUI, or run this from a shell:

```bash
/usr/local/sbin/zfs_autosnapshot
```

## ZFS Send

ZFS Send is for replicating selected datasets to destination datasets.

Each send job has:

- a source dataset
- a destination dataset
- a frequency
- an option to include child datasets
- a destination free-space target

ZFS Send uses its own send checkpoint snapshots instead of the normal autosnapshot prefix. That keeps replication checkpoints separate from regular autosnapshot cleanup.

The send page also has a queue view. Scheduled sends and one-off sends go through the same queue, so you can see what is waiting, running, failed, or ready to retry. Active jobs show step and progress updates when the browser supports it.

Transport choices are per send job:

- Local sends replicate to a destination dataset visible on the same Unraid host.
- SSH transport can send over the network using non-interactive SSH. Configure the remote host, port, user, and optional local private-key path; the plugin stores connection metadata and paths, not raw passwords or private-key contents.
- spiped code and config plumbing are retained for future encrypted transport work, but the feature is incomplete and intentionally hidden from the WebGUI. Use SSH for active network sends until spiped receiver-side inventory and receive verification are implemented.

Destination cleanup uses the same keep-all, daily, and weekly style retention policy. The newest confirmed send checkpoint is protected so the next incremental send still has a base snapshot. For SSH sends, cleanup/protection uses remote SSH destination snapshots instead of assuming the destination dataset exists locally.

## Dataset Migrator

Dataset Migrator is for reorganizing a dataset that has several top-level folders and turning those folders into real child datasets.

A common use case is an `appdata` dataset for Docker containers. The migrator can turn each application's configuration folder into its own child dataset. Then each app can have its own snapshots, so you can roll back one damaged or deleted app folder without reverting the entire appdata dataset and losing changes from every other app.

The migrator is careful on purpose:

1. You choose the parent dataset.
2. It scans the top-level folders and shows the migration plan.
3. It skips unsafe names, existing child datasets, and anything that does not look safe to move.
4. Before copying, it records running Docker containers.
5. It stops those containers and temporarily disables their Docker restart policy.
6. It copies each folder into a new child dataset.
7. It verifies the copy with file manifests and checksums.
8. It restores Docker restart policies and starts the containers again.

Because it verifies the copy, it can be slow. That is expected.

Stop any watchdogs or outside tools that might restart containers before you use it. If something relaunches containers during the migration, the tool may abort to avoid an unsafe copy. If free space runs low, the migration can pause and wait for you to free enough space before continuing.

## Snapshot Manager

Snapshot Manager works on one dataset at a time, with server-side search, filtering and pages of 50, 100 or 250 snapshots (100 by default). It supports large inventories, including 10,000-snapshot datasets. Filter by name, prefix/origin, dates, age, Used/Written bytes, holds, replication protection and pending actions. Dataset search works alongside the pool filter.

Used and Written measure different properties. Used is space exclusively referenced by that snapshot; Written is referenced space written since its predecessor. A zero value does not mean the snapshot contains no files. Written totals are not a reclaimable-space estimate.

Shift-click selects or deselects a range on the current page, skipping disabled rows. Selection survives sorting, paging and status refreshes. Changing the dataset or filters clears selection with an explanation. **Select all matching** captures existing snapshot identities; later snapshots do not join it.

Bulk Delete, **Add plugin hold** and **Release plugin hold** open an exact-snapshot review before submission. External holds are displayed separately and cannot be released by the plugin. Send and Rollback act on one snapshot. Rollback refuses to remove newer, unselected snapshots. Ordinary Delete never expands to source/destination trees.

Large selections upload automatically in requests of at most 500 identities and execute in chunks of at most 50. The review and status panel shows eligible, excluded, queued, completed, skipped and failed items, including individual errors. Duplicate submission of an approved batch does not repeat work. Retry creates a fresh review containing failed items only.

**Preview cleanup** offers zero-change cleanup (retaining zero-written anchors and the newest snapshot) and the dataset's configured keep-all/daily/weekly retention policy. Auto Snapshot-managed snapshots are the default scope; retention requires a configured managed dataset. Holds, clones, replication checkpoints/bases, active transfers, pending deletion and incomplete metadata exclude snapshots. Preview makes no changes, expires after five minutes, and binds approval to exact names and GUIDs. Configuration or identity changes require a new preview or cause items to be skipped. Pool-wide low-space cleanup remains a separate automatic action.

Recovery/Repair Tools remain removed. Legacy Snapshot Manager queue files are not replayed by the new batch worker; select and review those actions again.

## Safe cancellation and settings

Cancel saves the cancellation decision before signaling the entire current replication run. It persistently pauses that schedule until **Resume**. Canceled jobs cannot be retried or recreated by stale workers. A real crash can recover after surviving processes from the old attempt have stopped. Schedule completion requires explicit success from every expected child.

Replication refuses destructive reseeding and forced receive rollback. Existing destinations require a verified common base or a matching resumable receive. Destination/base GUIDs and resume targets are checked before transfer. Resolve conflicts explicitly; the plugin will not destroy destination data to make a send succeed.

Auto Snapshot and ZFS Send prefixes must differ, and neither may start with the other: `snap-` conflicts with `snap-send-`; `snap-auto-` and `snap-send-` are allowed. Both pages show the other configured prefix. Existing conflicts block affected automatic cleanup and replication until fixed. Previously used send prefixes remain protected; changing a prefix does not rename or delete snapshots.

**Restore tuning defaults** populates the form; choose Save to apply. Auto Snapshot resets retention to 14/30/183 days and disables the schedule with its default timing, preserving dataset choices, thresholds, prefix and Dry Run. ZFS Send resets retention to 14/30/183, parallelism to 1, rate limit to 0 and preparation concurrency to 16, preserving job definitions/frequencies, prefixes, connections and schedule pauses. Unsaved changes are indicated and trigger a navigation warning. Saves are atomic and reject stale page revisions. A scheduler failure is reported separately when configuration was saved successfully.

During an upgrade or removal, a maintenance marker blocks new work while workers stop. Shutdown is verified before ownership is released. Queue records and permanent lock files survive upgrades. A failed upgrade retains `/boot/config/plugins/zfs.autosnapshot/maintenance`; rerun installation after resolving the reported shutdown error.

Validation evidence and remaining operational limits are in [the reliability audit](docs/reliability-audit.md).

## Logs and diagnostics

The main settings page includes run output and debug logs.

The Help tab has a diagnostics download. The diagnostics zip is meant for GitHub issues and includes redacted plugin config, plugin logs, queue state, and read-only ZFS/zpool/system summaries.

When reporting a bug, include:

- what happened
- which system was affected
- how to reproduce it, if you know
- plugin version
- Unraid version
- diagnostics zip

GitHub issues:

```text
https://github.com/bstone108/zfsautosnapshot-unraid/issues
```

Support thread:

```text
https://forums.unraid.net/topic/197348-plugin-zfs-auto-snapshot/
```

## Files on Unraid

Main config:

```text
/boot/config/plugins/zfs.autosnapshot/zfs_autosnapshot.conf
```

Main command:

```text
/usr/local/sbin/zfs_autosnapshot
```

Plugin WebGUI files:

```text
/usr/local/emhttp/plugins/zfs.autosnapshot/
```

You can edit the config file by hand if needed, but the WebGUI is the intended path.

## Development notes

Release artifacts are built by GitHub Actions. The source template is:

```text
zfs.autosnapshot.plg.in
```

The generated plugin manifest and package are written under `dist/` during the build.

For a normal release:

1. Update `VERSION`.
2. Update `CHANGELOG.md`.
3. Update `zfs.autosnapshot.plg.in`.
4. Push the branch.
5. Let GitHub Actions build and commit the generated artifacts.

To reproduce a package locally:

```bash
./scripts/build-release.sh <version> <base_url>
```

## License

MIT License.
