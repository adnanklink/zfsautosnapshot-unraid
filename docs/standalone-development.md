# Standalone development direction

The plugin will become a separately named standalone project. Compatibility with
the original plugin's runtime queues and an automatic upgrade bridge are no longer
delivery requirements. Existing ZFS data-safety checks remain requirements. A
configuration importer, if useful later, must be an explicit reviewed operation.
The working name and installation identity are still undecided.

The current source still uses the original plugin ID and paths. Renaming a page
or repository does not provide coexistence: packaging must assign independent
configuration, runtime, service, cron, UI and update identities before a standalone
release can be installed alongside the original. Both plugins operating on the
same datasets would still require resource coordination or one scheduler disabled.

The implementation order is:

1. Complete native coordinator replication phases: bounded destination inspection,
   exact reference registration, cleanup dependencies, measured space approval,
   transfer and explicit finalization of all expected children. Keep destructive
   validation, forced receive rollback and automatic destination removal disabled.
2. Add independently validated shared cleanup owners. Cancel detaches one owner;
   work can continue only while another valid authorization remains. Finish native
   schedule admission, manual Retry and configuration revision revalidation.
3. Route Auto Snapshot mutations through coordinator tasks and finish shared
   exclusion with Dataset Migrator. Retain safety-critical migration recovery
   checkpoints; all recurring runtime data stays in RAM.
4. Apply the chosen standalone identity, package/update URLs and documentation.
   Provide a clean installation and update path for standalone clients. Do not
   silently claim or replay the original plugin's pending work.
5. Finish all-path flash-write tracing, scale/idle behavior, failure/reboot tests,
   browser regressions and real-ZFS pipeline acceptance before publishing a release.

Implemented preparation currently supports a local, existing receiver and one
explicit source snapshot. Inspection compares GUIDs, bounds commands and output,
rechecks identities, and flags receive-token recovery without exposing the token.
It is a read-only phase, not a transfer approval. SSH, absent receivers, recursive
member planning, transfer admission and end-to-end replication remain unfinished.

See [the implementation record](job-coordination-progress.md) and
[verification audit](reliability-audit.md) for evidence and limitations.
