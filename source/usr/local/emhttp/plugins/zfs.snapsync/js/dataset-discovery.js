(function () {
  'use strict';
  const body = document.getElementById('async-dataset-rows');
  const sources = document.querySelectorAll('select[name^="job_source["], #new_job_source');
  if (!body && !sources.length) return;
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
  fetch('/plugins/zfs.snapsync/php/dataset-inventory.php', {credentials: 'same-origin', signal: controller.signal})
    .then(response => response.text()).then(text => {
      const match = text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
      const payload = JSON.parse(match ? match[1] : text);
      if (!payload.ok) throw new Error(payload.error);
      if (body) {
        const existing = new Map(Array.from(body.querySelectorAll('input[type="hidden"]'), input => [input.value, input.closest('tr')]));
        let index = existing.size;
        const fragment = document.createDocumentFragment();
        payload.datasets.forEach(row => {
          if (existing.has(row.dataset)) { existing.get(row.dataset).querySelector('[data-undetected]')?.remove(); return; }
          const tr = document.createElement('tr'); tr.className = 'zfsas-dataset-row'; tr.dataset.pool = row.pool;
          const disabled = row.sendDestination ? ' disabled' : '';
          tr.innerHTML = '<td class="zfsas-center"><input type="hidden" name="dataset_name[' + index + ']" value="' + escape(row.dataset) + '"><input class="zfsas-dataset-checkbox" type="checkbox" name="dataset_selected[' + index + ']" value="1"' + disabled + '></td><td><code>' + escape(row.dataset) + '</code> <span class="zfsas-pool-chip">' + escape(row.pool) + '</span>' + (row.sendDestination ? ' <span class="zfsas-badge">Reserved for ZFS Send destination</span>' : '') + '</td><td><input class="zfsas-input zfsas-threshold-input" name="dataset_threshold[' + index + ']" value="100G"' + disabled + '></td>';
          fragment.appendChild(tr); index++;
        });
        body.appendChild(fragment);
        const pool = document.getElementById('dataset_pool_filter'), known = new Set(Array.from(pool.options, option => option.value));
        [...new Set(payload.datasets.map(row => row.pool))].sort().forEach(name => { if (!known.has(name)) pool.add(new Option(name, name)); });
        document.dispatchEvent(new Event('zfsas:datasets-ready'));
      }
      sources.forEach(select => {
        const current = select.value, known = new Set(Array.from(select.options, option => option.value));
        payload.datasets.forEach(row => { if (!known.has(row.dataset)) select.add(new Option(row.dataset, row.dataset)); });
        select.value = current;
      });
    }).catch(error => {
      const label = document.getElementById('dataset-discovery-status');
      if (label) label.textContent = 'Dataset discovery failed. Saved selections are preserved. ' + error.message;
    }).finally(() => clearTimeout(timer));
}());
