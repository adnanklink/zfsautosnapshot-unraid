<a href="?section=tools">← All tools</a>
<div class="ui-notice">Select a dataset, review its folders and container handling, then start. Disable outside container watchdogs before starting.</div>
<div class="zfsas-dm-page">
  <div class="zfsas-dm-grid">
    <div class="zfsas-dm-card">
      <h3 style="margin-top:0;">1. Select a dataset</h3><details><summary>How migration works and precautions</summary>
      <div class="zfsas-dm-help">
        This tool only works on top-level folders directly under the selected dataset mountpoint. It converts each eligible folder into a child ZFS dataset by copying to a temporary dataset, verifying the copy with manifests and checksums, then replacing the original folder path with the new dataset. Because this uses multiple verification passes, it is intentionally slow.
      </div>
      <div class="zfsas-alert zfsas-alert-info">
        <div><strong>1. Preview first:</strong> Preview Migration scans the selected parent dataset, finds top-level folders, and builds this review list without stopping containers or moving data.</div>
        <div style="margin-top:6px;"><strong>2. Start only after review:</strong> Start Migration only begins work after you review the preview. The worker then checks space for each next folder before touching containers or the original folder.</div>
        <div style="margin-top:6px;"><strong>3. Space waits are safe:</strong> If space is too low before the next folder, containers stay running and the original folder stays in place until enough free space is available. The warning clears and the migration resumes automatically after you free space manually or automatic snapshot cleanup frees enough space.</div>
      </div>
      <div class="zfsas-alert zfsas-alert-warn">
        <div>Stop all watchdog scripts or plugins before starting. Anything that relaunches containers during the migration can corrupt the copy and cause the tool to abort.</div>
        <div style="margin-top:6px;">Any containers that are set to restart automatically may still be restarted by outside tooling. The migrator will temporarily disable Docker restart policies for containers it stops, but you still need to disable outside watchdog behavior first.</div>
      </div>
      <div class="zfsas-alert zfsas-alert-info">
        <div>Do not start containers again until the tool finishes. During active folder work, containers that use the folder being migrated may be stopped until verification and restoration complete.</div>
        <div style="margin-top:6px;">Folder names must already be valid ZFS child dataset names. Anything with an unsafe name, nested mount, or existing child dataset will be skipped and called out below.</div>
      </div>
      </details><div id="migrate_feedback"></div>
      <div class="zfsas-dm-toolbar">
        <select aria-label="Dataset to migrate" id="migrate_dataset" class="zfsas-dm-select"></select>
        <button type="button" class="btn" id="migrate_preview">Preview Migration</button>
        <button type="button" class="btn" id="migrate_refresh">Refresh</button>
        <div id="migrate_page_status" class="zfsas-dm-status">Loading dataset migrator status...</div>
      </div>
    </div>

  </div>

  <div class="zfsas-dm-card">
    <h3 style="margin-top:0;">2. Review folders and containers</h3>
    <div class="zfsas-dm-help">Eligible rows will be migrated into child datasets. During an active run this table switches to the worker's live folder state.</div>
    <div id="migrate_folder_source_notice" class="zfsas-dm-source-notice"></div>
    <div class="zfsas-table-wrap">
      <table class="zfsas-table">
        <thead>
          <tr>
            <th>Folder</th>
            <th>Target dataset</th>
            <th>Size</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody id="migrate_folder_rows">
          <tr>
            <td colspan="6" class="zfsas-dm-help">Choose a dataset to see its top-level folder plan.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="zfsas-dm-card">
    <h3 style="margin-top:0;">Container Handling</h3>
    <div class="zfsas-dm-help">Before the copy starts, the tool records which containers were running, temporarily disables their Docker restart policy while the migration is active, then brings them back up afterward. If any container fails to start, the tool starts the rest, waits 5 minutes, and retries the failed ones once.</div>
    <div id="migrate_container_source_notice" class="zfsas-dm-source-notice"></div>
    <div class="zfsas-table-wrap">
      <table class="zfsas-table">
        <thead>
          <tr>
            <th>Container</th>
            <th>Restart policy</th>
            <th>Worker status</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody id="migrate_container_rows">
          <tr>
            <td colspan="4" class="zfsas-dm-help">Container preflight data will load in a moment.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="zfsas-dm-card">
    <h3>3. Start reviewed migration</h3>
    <label><input type="checkbox" id="migrate-review-confirm"> I reviewed the folder plan and container handling, and disabled outside container watchdogs.</label>
    <button type="button" class="btn btn-primary" id="migrate_start" disabled>Start Migration</button>
    <p class="muted">Preview the selected dataset before starting. The worker revalidates the paths and available space.</p>
  </div>
    <div class="zfsas-dm-card" id="migration-monitor">
      <h3 style="margin-top:0;">4. Monitor migration</h3>
      <div class="zfsas-dm-help">This reflects the active worker state. The current folder and overall progress bars update while the background job is running.</div>
      <div id="migrate_waiting_notice"></div>
      <div class="zfsas-dm-summary-grid">
        <div class="zfsas-dm-stat">
          <div class="zfsas-dm-stat-label">State</div>
          <div class="zfsas-dm-stat-value" id="summary_state">Idle</div>
        </div>
        <div class="zfsas-dm-stat">
          <div class="zfsas-dm-stat-label">Dataset</div>
          <div class="zfsas-dm-stat-value" id="summary_dataset">-</div>
        </div>
        <div class="zfsas-dm-stat">
          <div class="zfsas-dm-stat-label">Folders</div>
          <div class="zfsas-dm-stat-value" id="summary_folders">0 / 0</div>
        </div>
        <div class="zfsas-dm-stat">
          <div class="zfsas-dm-stat-label">Current Step</div>
          <div class="zfsas-dm-stat-value" id="summary_step">-</div>
        </div>
      </div>
      <div style="margin-top:14px;">
        <div class="zfsas-dm-help">Overall progress</div>
        <div id="summary_overall_progress"></div>
      </div>
      <div style="margin-top:12px;">
        <div class="zfsas-dm-help">Current folder progress</div>
        <div id="summary_folder_progress"></div>
      </div>
      <div id="summary_message" class="zfsas-alert zfsas-alert-info" style="display:none;"></div>
    </div>

  <div class="zfsas-dm-card">
    <h3 style="margin-top:0;">Worker log</h3>
    <div class="zfsas-dm-help">This shows the most recent log lines from the background worker, including copy, verification, rollback, free-space waits, and container restart attempts.</div>
    <pre id="migrate_log" class="zfsas-dm-log">Loading log...</pre>
  </div>
</div>

<script type="application/json" id="migration-options"><?php echo json_encode([$statusUrl, $actionUrl], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="/plugins/zfs.autosnapshot/js/migration.js"></script>
