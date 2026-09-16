(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const base = '/plugins/zfs.autosnapshot/php/';
  const selection = new SnapshotSelection();
  const resources = new Map();
  let embeddedVisible = true;
  const visible = () => !document.hidden && embeddedVisible;
  function reportHeight() { if (window.parent !== window) window.parent.postMessage({type: 'zfsas:snapshot-manager:height', height: document.documentElement.scrollHeight}, window.location.origin); }
  new ResizeObserver(reportHeight).observe(document.body);
  window.addEventListener('message', event => {
    if (event.origin !== window.location.origin || event.source !== window.parent) return;
    if (event.data?.type === 'zfsas:snapshot-manager:request-height') reportHeight();
    if (event.data?.type === 'zfsas:snapshot-manager:visibility') { embeddedVisible = !!event.data.visible; if (visible()) { loadSnapshots(); batchStatus(); } }
  });
  let datasets = [], rows = [], page = 1, pages = 1, sort = 'creation', direction = 'desc';
  let batch = null, reviewPage = 1, busy = false, filterTimer = null;
  const labels = {delete: 'Delete', hold: 'Add plugin hold', release: 'Release plugin hold', rollback: 'Rollback', take_snapshot: 'Take snapshot'};
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  function notice(message, error = false) { $('notice').textContent = message; $('notice').classList.toggle('error', error); }
  function filters() { return Object.assign(Object.fromEntries(new FormData($('filters')).entries()), {sort, direction, page, page_size: $('page-size').value}); }
  function parse(text) {
    const match = text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
    return JSON.parse(match ? match[1] : text);
  }
  async function request(resource, endpoint, data, method = 'GET', replace = false) {
    if (resources.has(resource)) {
      if (!replace) return null;
      resources.get(resource).abort();
    }
    const controller = new AbortController(); resources.set(resource, controller);
    const timer = setTimeout(() => controller.abort(), 20000);
    const params = new URLSearchParams();
    Object.keys(data).forEach(key => {
      if (Array.isArray(data[key])) data[key].forEach(value => params.append(key + '[]', value));
      else params.set(key, data[key]);
    });
    if (method === 'POST') params.set('csrf_token', document.querySelector('meta[name="csrf_token"]').content);
    try {
      const response = await fetch(base + endpoint + (method === 'GET' ? '?' + params : ''), {
        method, credentials: 'same-origin', signal: controller.signal,
        headers: method === 'POST' ? {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'} : {},
        body: method === 'POST' ? params : undefined
      });
      const payload = parse(await response.text());
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Request failed.');
      return payload;
    } finally {
      clearTimeout(timer);
      if (resources.get(resource) === controller) resources.delete(resource);
    }
  }
  function selectedStatus() {
    $('selected-count').textContent = selection.items.size + ' selected';
    const descriptions = [];
    ['delete', 'hold', 'release'].forEach(action => {
      let eligible = 0; const exclusions = new Map();
      selection.items.forEach(row => {
        const reason = row.eligibility ? row.eligibility[action] : 'Review required';
        if (!reason) eligible++;
        else exclusions.set(reason, (exclusions.get(reason) || 0) + 1);
      });
      const button = document.querySelector('[data-bulk="' + action + '"]');
      button.disabled = busy || selection.items.size === 0;
      descriptions.push(labels[action] + ': ' + eligible + ' eligible' + (exclusions.size ? ' (' + Array.from(exclusions, ([reason, count]) => count + ' ' + reason).join('; ') + ')' : ''));
    });
    $('eligibility').textContent = descriptions.join(' · ') + (selection.items.size ? '. Eligibility is checked again during review.' : '');
    const header = selection.header(rows);
    $('page-checkbox').checked = header.checked; $('page-checkbox').indeterminate = header.indeterminate;
    $('snapshots').querySelectorAll('[data-select]').forEach(input => { input.checked = selection.items.has(input.dataset.select); });
  }
  function renderRows() {
    const active = document.activeElement;
    const focused = active && (active.dataset.select || (active.dataset.single && rows[Number(active.dataset.index)]?.identity));
    const focusedAction = active && active.dataset.single;
    const html = rows.map((row, index) => {
      const badges = [];
      if (row.pluginHeld) badges.push('Plugin hold');
      (row.externalHoldTags || []).forEach(tag => badges.push('External hold: ' + tag));
      if (row.sendProtected) badges.push('Replication protected');
      if (row.activeTransfer) badges.push('Transfer pending');
      if (row.pendingAction) badges.push('Pending ' + row.pendingAction);
      if (!row.metadataComplete) badges.push('Incomplete metadata');
      if ((row.clones || []).length) badges.push('Clone dependencies');
      return '<tr><td><input type="checkbox" data-index="' + index + '" data-select="' + escape(row.identity) + '" aria-label="Select ' + escape(row.snapshotName) + '" ' + (selection.items.has(row.identity) ? 'checked ' : '') + (!selection.selectable(row) ? 'disabled' : '') + '></td>' +
        '<td><code>' + escape(row.snapshotName) + '</code></td><td>' + escape(row.createdText) + '</td><td>' + escape(row.usedText) + '</td><td>' + escape(row.writtenText) + '</td><td>' + badges.map(badge => '<span class="badge">' + escape(badge) + '</span>').join(' ') + '</td><td>' +
        ['send', 'rollback'].map(action => '<button data-single="' + action + '" data-index="' + index + '" title="' + escape(row.eligibility[action] || '') + '" ' + (row.eligibility[action] ? 'disabled' : '') + '>' + (action === 'send' ? 'Send' : 'Rollback') + '</button>').join(' ') + '</td></tr>';
    }).join('') || '<tr><td colspan="7">No matching snapshots.</td></tr>';
    const body = $('snapshots');
    if (body._html !== html) {
      const scroll = {x: window.scrollX, y: window.scrollY};
      const wrap = body.closest('.table-wrap'), left = wrap.scrollLeft;
      body.innerHTML = html; body._html = html;
      if (focused) {
        const checkbox = Array.from(body.querySelectorAll('[data-select]')).find(input => input.dataset.select === focused);
        const target = focusedAction ? checkbox?.closest('tr').querySelector('[data-single="' + focusedAction + '"]') : checkbox;
        target?.focus({preventScroll: true});
      }
      wrap.scrollLeft = left; window.scrollTo(scroll.x, scroll.y);
    }
    selectedStatus();
  }
  async function loadSnapshots(replace = false) {
    if (!selection.dataset || !visible()) return;
    const stamp = selection.stamp();
    try {
      const payload = await request('inventory', 'snapshot-manager-dataset.php', Object.assign({dataset: selection.dataset}, filters()), 'GET', replace);
      if (!payload || !selection.accepts(stamp) || payload.dataset !== stamp.dataset) return;
      rows = payload.snapshots; page = payload.page; pages = payload.pages;
      selection.refresh(rows); renderRows();
      $('counts').textContent = payload.matching + ' matching / ' + payload.total + ' total snapshots';
      $('page-text').textContent = 'Page ' + page + ' of ' + pages;
      $('previous').disabled = page <= 1; $('next').disabled = page >= pages;
    } catch (error) { if (error.name !== 'AbortError' && selection.accepts(stamp)) notice(error.message, true); }
  }
  function datasetOptions() {
    const query = $('dataset-search').value.toLowerCase(), pool = $('pool').value;
    const filtered = datasets.filter(row => (!pool || row.pool === pool) && row.dataset.toLowerCase().includes(query));
    $('dataset').innerHTML = '<option value="">Choose a dataset</option>' + filtered.map(row => '<option value="' + escape(row.dataset) + '">' + escape(row.dataset) + ' (' + row.snapshotCount + ')</option>').join('');
    $('dataset').value = selection.dataset;
  }
  async function loadDatasets() {
    try {
      const payload = await request('datasets', 'snapshot-manager-list.php', {});
      if (!payload) return;
      datasets = payload.datasets;
      const pool = $('pool').value;
      $('pool').innerHTML = '<option value="">All pools</option>' + [...new Set(datasets.map(row => row.pool))].sort().map(pool => '<option>' + escape(pool) + '</option>').join('');
      $('pool').value = pool; datasetOptions();
    } catch (error) { if (error.name !== 'AbortError') notice(error.message, true); }
  }
  function changeContext(dataset, message) {
    selection.context(dataset); rows = []; page = 1;
    batch = null; $('review').hidden = true;
    $('manager').hidden = !dataset; $('cleanup').hidden = !dataset; $('dataset-title').textContent = dataset;
    notice(message); selectedStatus(); loadSnapshots(true);
    try {
      const token = sessionStorage.getItem('zfsas-batch:' + dataset);
      if (token) { batch = {token}; reviewPage = 1; batchStatus(true); }
    } catch (_) {}
  }
  function changedFilters() {
    changeContext(selection.dataset, 'Filters changed. Selection cleared so actions stay within the displayed filter.');
  }
  function renderBatch(payload) {
    if (payload.dataset !== selection.dataset) return;
    batch = payload; reviewPage = payload.page;
    try { sessionStorage.setItem('zfsas-batch:' + payload.dataset, payload.token); } catch (_) {}
    $('review').hidden = false;
    $('review-title').textContent = (payload.state === 'review' ? 'Review: ' : 'Batch: ') + labels[payload.action];
    $('review-summary').textContent = 'Dataset: ' + payload.dataset + ' · ' + payload.selected + ' snapshots in review · ' + payload.eligible + ' eligible · approval expires ' + new Date(payload.expires * 1000).toLocaleTimeString() + '.';
    $('batch-counts').textContent = Object.entries(payload.counts).map(([name, count]) => name + ': ' + count).join(' · ');
    $('approve').hidden = payload.state !== 'review'; $('approve').disabled = !payload.eligible || busy || Date.now() > payload.expires * 1000;
    $('retry-failed').hidden = !payload.counts.failed; $('retry-failed').disabled = busy;
    $('review-items').innerHTML = payload.items.map(item => '<tr><td><code>' + escape(item.snapshot) + '</code></td><td>' + escape(item.guid) + '</td><td>' + escape(payload.state === 'review' ? (item.candidate ? 'Eligible' : 'Excluded') : item.state) + '</td><td>' + escape(item.error || item.reason) + '</td></tr>').join('');
    $('review-page').textContent = 'Page ' + payload.page + ' of ' + payload.pages;
    $('review-prev').disabled = payload.page <= 1; $('review-next').disabled = payload.page >= payload.pages;
  }
  async function batchStatus(replace = false) {
    if (!batch || !visible()) return;
    const stamp = selection.stamp(), token = batch.token, requestedPage = reviewPage;
    try {
      const payload = await request('batch-status', 'snapshot-manager-batch.php', {action: 'status', token, page: requestedPage}, 'GET', replace);
      if (payload && selection.accepts(stamp) && batch && batch.token === token && reviewPage === requestedPage) renderBatch(payload);
    } catch (error) { if (error.name !== 'AbortError' && selection.accepts(stamp)) notice(error.message, true); }
  }
  async function perform(action) {
    if (busy) return;
    busy = true; selectedStatus(); $('approve').disabled = true;
    const stamp = selection.stamp();
    try { await action(stamp); }
    catch (error) { if (error.name !== 'AbortError' && selection.accepts(stamp)) notice(error.message, true); }
    finally { busy = false; selectedStatus(); if (batch) renderBatch(batch); }
  }
  async function reviewSelection(operation, stamp) {
    const items = Array.from(selection.items.values(), row => ({snapshot: row.snapshot, guid: row.guid}));
    let token = '', payload;
    for (let i = 0; i < items.length; i += 500) {
      if (!selection.accepts(stamp)) return;
      payload = await request('action', 'snapshot-manager-batch.php', {action: 'capture', dataset: stamp.dataset, operation, token, items: JSON.stringify(items.slice(i, i + 500)), seal: i + 500 >= items.length ? '1' : '0'}, 'POST');
      token = payload.token;
    }
    if (payload && selection.accepts(stamp)) { renderBatch(payload); $('review').scrollIntoView({block: 'start'}); }
  }
  $('dataset-search').addEventListener('input', datasetOptions); $('pool').addEventListener('change', datasetOptions);
  $('reload-datasets').addEventListener('click', loadDatasets);
  $('dataset').addEventListener('change', () => changeContext($('dataset').value, 'Dataset changed. Selection cleared.'));
  $('filters').addEventListener('submit', event => event.preventDefault());
  $('filters').addEventListener('input', () => { clearTimeout(filterTimer); selection.context(selection.dataset); selectedStatus(); filterTimer = setTimeout(changedFilters, 250); });
  $('filters').addEventListener('reset', () => setTimeout(changedFilters, 0));
  document.querySelectorAll('[data-quick]').forEach(button => button.addEventListener('click', () => {
    $('filters').reset();
    const key = button.dataset.quick;
    if (key === 'used' || key === 'written') { $('filters').elements[key + '_min'].value = 0; $('filters').elements[key + '_max'].value = 0; }
    else $('filters').elements.origin.value = key;
    // The reset handler applies these values once, after reset completes.
  }));
  $('snapshots').addEventListener('click', event => {
    const input = event.target.closest('[data-select]');
    if (input && !input.disabled) { selection.toggle(rows, Number(input.dataset.index), input.checked, event.shiftKey); selectedStatus(); }
    const button = event.target.closest('[data-single]');
    if (button) perform(async stamp => {
      const row = rows[Number(button.dataset.index)], action = button.dataset.single;
      const data = {dataset: stamp.dataset, action, snapshots: [row.snapshot], guid: row.guid};
      if (action === 'send') {
        const destination = window.prompt('Destination dataset for ' + row.snapshot + ':');
        if (!destination) return; data.destination = destination;
      }
      const payload = await request('action', 'snapshot-manager-action.php', data, 'POST');
      if (!selection.accepts(stamp)) return;
      if (payload.token) renderBatch(payload); else { notice(payload.message); loadSnapshots(true); }
    });
  });
  $('select-page').addEventListener('click', () => { selection.page(rows, true); selectedStatus(); });
  $('page-checkbox').addEventListener('change', event => { selection.page(rows, event.target.checked); selectedStatus(); });
  $('clear-selection').addEventListener('click', () => { selection.clear(); selectedStatus(); notice('Selection cleared.'); });
  $('select-matching').addEventListener('click', () => perform(async stamp => {
    const payload = await request('action', 'snapshot-manager-batch.php', {action: 'matching', dataset: stamp.dataset, filters: JSON.stringify(filters())}, 'POST');
    if (!selection.accepts(stamp)) return;
    selection.capture(payload.items); selectedStatus(); notice('Captured ' + payload.items.length + ' matching identities. New snapshots will not join this selection.');
  }));
  document.querySelectorAll('[data-bulk]').forEach(button => button.addEventListener('click', () => perform(stamp => reviewSelection(button.dataset.bulk, stamp))));
  document.querySelectorAll('[data-sort]').forEach(button => button.addEventListener('click', () => {
    direction = sort === button.dataset.sort && direction === 'desc' ? 'asc' : 'desc'; sort = button.dataset.sort;
    selection.sortChanged(); page = 1; loadSnapshots(true);
  }));
  $('previous').addEventListener('click', () => { page--; selection.sortChanged(); loadSnapshots(true); });
  $('next').addEventListener('click', () => { page++; selection.sortChanged(); loadSnapshots(true); });
  $('page-size').addEventListener('change', () => { page = 1; selection.sortChanged(); loadSnapshots(true); });
  $('preview-cleanup').addEventListener('click', () => perform(async stamp => {
    const payload = await request('action', 'snapshot-manager-batch.php', {action: 'cleanup', dataset: stamp.dataset, mode: $('cleanup-mode').value, managed_only: $('cleanup-scope').value}, 'POST');
    if (selection.accepts(stamp)) renderBatch(payload);
  }));
  $('take-snapshot').addEventListener('click', () => perform(async stamp => {
    const payload = await request('action', 'snapshot-manager-action.php', {action: 'take_snapshot', dataset: stamp.dataset, snapshot_name: $('snapshot-name').value}, 'POST');
    if (selection.accepts(stamp)) renderBatch(payload);
  }));
  $('approve').addEventListener('click', () => perform(async stamp => {
    const payload = await request('action', 'snapshot-manager-batch.php', {action: 'submit', token: batch.token, dataset: stamp.dataset}, 'POST');
    if (selection.accepts(stamp)) { renderBatch(payload); notice('Batch submitted. Exact snapshot identities will be revalidated.'); loadSnapshots(true); }
  }));
  $('retry-failed').addEventListener('click', () => perform(async stamp => {
    const payload = await request('action', 'snapshot-manager-batch.php', {action: 'retry', token: batch.token, dataset: stamp.dataset}, 'POST');
    if (selection.accepts(stamp)) renderBatch(payload);
  }));
  $('close-review').addEventListener('click', () => { try { sessionStorage.removeItem('zfsas-batch:' + selection.dataset); } catch (_) {} batch = null; $('review').hidden = true; });
  $('review-prev').addEventListener('click', () => { reviewPage--; batchStatus(true); });
  $('review-next').addEventListener('click', () => { reviewPage++; batchStatus(true); });
  document.addEventListener('visibilitychange', () => { if (visible()) { loadSnapshots(); batchStatus(); } });
  window.setInterval(() => { if (visible()) { loadSnapshots(); batchStatus(); } }, 5000);
  loadDatasets();
}());
