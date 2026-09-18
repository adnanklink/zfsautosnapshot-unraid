<div class="ui-tool-grid">
<section class="ui-card"><span class="ui-kicker">DATA ORGANIZATION</span><h2>Dataset Migrator</h2><p>Convert top-level folders into child datasets. Review the folder plan and affected containers before starting.</p><a class="btn btn-primary" href="<?= zfsas_ui_url('tools', 'migrator') ?>">Open migrator <span aria-hidden="true">→</span></a></section>
<section class="ui-card"><span class="ui-kicker">TROUBLESHOOTING</span><h2>Diagnostics</h2><p>Download a redacted report with configuration, runtime state, logs, and system information to help investigate an issue.</p><a class="btn" id="diagnostics_download" href="/plugins/zfs.snapsync/php/diagnostics.php" download="zfs_snapsync_diagnostics.zip">Download diagnostics</a><p class="ui-footnote">Generated on request. Review the report before sharing it.</p></section>
</div>
<section class="ui-card"><h2>Looking for logs?</h2><p>Find current work and recent results in Activity. Open the relevant operation to see available details.</p><a class="ui-subtle-link" href="<?= zfsas_ui_url('activity') ?>">Go to Activity →</a></section>

<?php require_once __DIR__ . '/../interface-settings.php'; $interface = zfsas_interface_read('/boot/config/plugins/zfs.snapsync'); ?>
<section class="ui-card"><h2>Interface</h2><p>Add a SnapSync tab to Unraid’s main navigation. The Settings entry remains available.</p>
<form id="interface-settings">
<input type="hidden" name="revision" value="<?= zfsas_ui_h($interface['revision']) ?>">
<label><input type="checkbox" name="show_tab" <?= $interface['enabled'] ? 'checked' : '' ?>> Show SnapSync in the Unraid navigation</label>
<p><button type="submit" class="btn">Save interface preference</button> <a id="interface-reload" class="btn" href="/Settings/ZFSSnapSync?section=tools" hidden>Reload navigation</a></p>
<p id="interface-status" role="status" aria-live="polite"></p>
</form></section>
<script src="/plugins/zfs.snapsync/js/interface-settings.js"></script>
