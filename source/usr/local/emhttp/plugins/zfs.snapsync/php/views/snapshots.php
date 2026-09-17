<section aria-label="Choose dataset"><div class="toolbar">
<label>Dataset search<input id="dataset-search" type="search" placeholder="Dataset name"></label>
<label>Pool<select id="pool"><option value="">All pools</option></select></label>
<label>Dataset<select id="dataset"><option value="">Loading datasets…</option></select></label>
<button id="reload-datasets" type="button">Refresh datasets</button></div></section>
<p id="notice" role="status" aria-live="polite"></p>
<section id="manager" hidden>
<h2 id="dataset-title"></h2>
<form id="filters" class="filters">
<label>Snapshot name<input name="search" type="search"></label><label>Prefix<input name="prefix"></label>
<label>Origin<select name="origin"><option value="">All origins</option><option value="auto">Auto Snapshot</option><option value="send">Send checkpoints</option><option value="other">Other snapshots</option></select></label>
<details class="ui-advanced"><summary>Advanced filters</summary><div class="filters"><label>Created from (UTC)<input name="from" type="date"></label><label>Through (UTC)<input name="until" type="date"></label>
<label>Age min (days)<input name="age_min" type="number" min="0" step="any"></label><label>Age max (days)<input name="age_max" type="number" min="0" step="any"></label>
<label>Used min (bytes)<input name="used_min" type="number" min="0"></label><label>Used max (bytes)<input name="used_max" type="number" min="0"></label>
<label>Written min (bytes)<input name="written_min" type="number" min="0"></label><label>Written max (bytes)<input name="written_max" type="number" min="0"></label>
<label>Held<select name="held"><option value="">Any</option><option value="yes">Held</option><option value="no">Not held</option></select></label>
<label>Replication protection<select name="protected"><option value="">Any</option><option value="yes">Protected</option><option value="no">Not protected</option></select></label>
<label>Pending action<select name="pending"><option value="">Any</option><option value="yes">Pending</option><option value="no">None</option></select></label>
</div></details><button type="reset">Clear filters</button></form>
<div class="toolbar" aria-label="Quick filters"><span>Quick filters:</span>
<button data-quick="used">Used = 0 B</button><button data-quick="written">Written = 0 B</button><button data-quick="auto">Auto Snapshot</button><button data-quick="send">Send checkpoints</button><button data-quick="other">Other snapshots</button></div>
<details class="ui-advanced"><summary>Understanding snapshot sizes</summary><p class="muted">Used measures space unique to a snapshot. Written measures referenced space written since the preceding snapshot. A zero value in either column does not mean there are no files. <a href="https://openzfs.github.io/openzfs-docs/man/v2.4/7/zfsprops.7.html" target="_blank" rel="noopener">OpenZFS property definitions</a>.</p></details>
<div class="toolbar sticky">
<strong id="selected-count">0 selected</strong><button id="select-page">Select this page</button><button id="select-matching">Select all matching</button><button id="clear-selection">Clear selection</button>
<button data-bulk="delete">Delete</button><button data-bulk="hold">Add plugin hold</button><button data-bulk="release">Release plugin hold</button>
<span id="eligibility"></span></div>
<div class="toolbar"><span id="counts"></span><button id="previous">Previous</button><span id="page-text"></span><button id="next">Next</button><label>Rows<select id="page-size"><option>50</option><option selected>100</option><option>250</option></select></label></div>
<div class="table-wrap"><table><thead><tr><th><input id="page-checkbox" type="checkbox" aria-label="Select this page"></th>
<th><button data-sort="name">Name</button></th><th><button data-sort="creation">Created</button></th>
<th title="Space referenced exclusively by this snapshot; shared blocks are excluded. Zero does not mean no files."><button data-sort="used">Used ⓘ</button></th>
<th title="Referenced space written since the previous snapshot. This is not a reclaimable-space estimate; zero does not mean no files."><button data-sort="written">Written ⓘ</button></th>
<th>Protection and pending actions</th><th>Single-snapshot actions</th></tr></thead><tbody id="snapshots"></tbody></table></div>
<div class="toolbar"><label>New snapshot name<input id="snapshot-name" placeholder="manual-2026-09-16"></label><button id="take-snapshot">Take snapshot</button></div>
</section>
<section id="cleanup" hidden><h2>Preview cleanup</h2><div class="toolbar"><label>Policy<select id="cleanup-mode"><option value="zero_change">Zero-change cleanup</option><option value="retention">Configured retention cleanup</option></select></label>
<label><span>Snapshot scope</span><select id="cleanup-scope"><option value="1">Auto Snapshot only</option><option value="0">All origins (zero-change only)</option></select></label><button id="preview-cleanup">Preview cleanup</button></div>
<p class="muted">Preview makes no changes. Newest snapshots, required anchors, holds, clones, replication references and incomplete metadata remain protected. Pool-wide low-space cleanup is separate. Written totals are not guaranteed reclaimable space.</p></section>
<section id="review" hidden aria-live="polite"><h2 id="review-title">Review batch</h2><p id="review-summary"></p><p id="batch-counts"></p>
<div class="toolbar"><button id="approve">Approve exact snapshots</button><button id="retry-failed">Review failed eligible items for retry</button><button id="close-review">Close review</button></div>
<div class="table-wrap"><table><thead><tr><th>Snapshot</th><th>GUID</th><th>State</th><th>Reason or error</th></tr></thead><tbody id="review-items"></tbody></table></div>
<div class="toolbar"><button id="review-prev">Previous items</button><span id="review-page"></span><button id="review-next">Next items</button></div></section>
<script src="/plugins/zfs.snapsync/js/snapshot-selection.js"></script><script src="/plugins/zfs.snapsync/js/snapshot-manager.js"></script>
