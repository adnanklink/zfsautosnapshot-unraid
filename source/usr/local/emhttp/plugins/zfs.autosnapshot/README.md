<b>ZFS Auto Snapshot</b><br>
WebUI-based ZFS snapshot scheduling for Unraid with plain-English scheduling,
dataset selection, retention cleanup, low-space protection, dry-run mode, and
built-in run logs.


## Reliable replication and Snapshot Manager batches

Snapshot Manager supports one dataset at a time with server-side filters, stable sorting, 50/100/250-row pages, cross-page selection and exact-identity batch review. “Select all matching” captures existing names and GUIDs. New snapshots never silently join a selection. Bulk Delete, Add plugin hold and Release plugin hold run in bounded chunks; Send and Rollback remain single-snapshot operations. External hold tags cannot be released by the plugin.

Cleanup previews default to Auto Snapshot-managed snapshots. Zero-change and configured retention policies retain newest snapshots and required anchors, and exclude holds, clones, replication references, pending deletes and incomplete metadata. Approval expires after five minutes; identities and protections are checked again during execution. Written bytes do not represent guaranteed reclaimable space.

Cancel persists the decision for the whole replication run before terminating processes and pauses its schedule until Resume. Receives no longer force rollback or destroy destinations to reseed. Prefix overlap is rejected on both settings pages and on the server. Restore tuning defaults requires Save and preserves datasets, prefixes, connections and schedule pauses. Concurrent/stale settings saves are rejected. The UI uses bounded polling and asynchronous dataset discovery.

Upgrade/removal preserves queue records and lock files and verifies process shutdown. Failed upgrade shutdown leaves a maintenance marker under the plugin configuration directory; resolve the reported process issue and rerun installation.
