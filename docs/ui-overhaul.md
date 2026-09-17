# UI preview: feat/ui-overhaul

The workspace is rendered by `workspace.php` through the existing Unraid `.page`. Section and tab names are allowlisted; links and browser history use normal URLs. Only the selected workflow is rendered. Legacy settings, send, migrator, and Snapshot Manager URLs enter the same shell; existing mutation endpoints remain in use.

## Workflows

- **Overview:** available runtime activity, failures needing attention, paused schedules, upcoming occurrences. Counts describe bounded recent records, not overall pool health or a lifetime history.
- **Snapshots:** Browse preserves GUID-bound selections, server pagination, five-minute review expiry, 500-item explicit uploads, 50-item execution chunks, and failed-only retries. Automation retains schedule conversion, previews, atomic revision-checked saves, and separate scheduler-application feedback.
- **Replication:** compact job list; Add/Edit drawers keep the existing field names and save contract. Cancel/Escape discards drawer edits. Done returns edits to the form; Save activates them. Shared SSH, retention, and concurrency controls are separate. Run Now requires saved settings.
- **Activity:** recent coordinator, replication, and migration records. Details show recorded status, retry time, waits, and run relationships. Actions are offered only when the underlying endpoint supports them. Cancel acknowledgment is distinct from verified worker shutdown. Logs are bounded and loaded when opened.
- **Tools:** Dataset Migrator and diagnostics. Migration requires a fresh preview and acknowledgment; polling reads RAM state without repeated dataset/Docker inspection. The worker still revalidates safety conditions before moving data.
- **Help:** operating guidance, cancellation, schedule semantics, reboot limits, and issue reporting.

## Runtime and scope

The new summary endpoint reads the local coordinator socket, bounded legacy send results, migration RAM status, and saved configuration. It does not start services or inspect ZFS/SSH/Docker inventories. Source failures are explicit. The legacy send adapter still scans its bounded retained job directory to assemble status; moving all replication state into the coordinator remains separate work. Summary polling uses two seconds while work is active and ten seconds while idle, stops when hidden, and rejects stale responses. Migration runtime polling uses ten seconds; explicit Preview performs inventory inspection.

No new persistent runtime store is introduced. Runtime history disappears on reboot. Manual sends are not recreated automatically, and interrupted batches require a fresh review. Persistent pauses and essential migration recovery checkpoints keep their existing behavior. The full replication coordinator is not completed by this UI change.

## Verification

`tests/reliability/workspace_browser.cjs` covers every section in desktop light/dark and tablet/mobile layouts, dialogs, migration review gating, hidden polling, focus return, and JavaScript errors. `config_browser.cjs` exercises real PHP-rendered forms, drawer cancel/commit, async dataset discovery, preserved tuning choices, dirty state and conflicts. `browser.cjs` retains the 10,000-snapshot fixed-selection and out-of-order-response checks.

`workspace_endpoints.php` exercises actual legacy routes and new read-only endpoints against a read-only `/boot` fixture, including missing coordinator, send relationships, stale migration, bounded logs and path allowlists. Run all endpoint fixtures in disposable containers, never on an installed server. Existing backend stage-one and reliability tests remain required. Container/browser fixtures do not substitute for a visual check inside the actual Unraid host theme.

## Installation and updates

Install `https://raw.githubusercontent.com/adnanklink/zfsautosnapshot-unraid/feat/ui-overhaul/dist/zfs.autosnapshot.plg` through **Plugins → Install Plugin** to switch to this preview. It is the same plugin identity and retains configuration; do not install two copies under different names. Let current jobs finish first. Preview clients receive later preview releases through Check for Updates. Clients on `fix/job-coordination` remain on that branch until explicitly switched.
