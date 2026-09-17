(() => {
const pageOptions = JSON.parse(document.getElementById('migration-options').textContent);

(function () {
  var statusUrl = pageOptions[0];
  var actionUrl = pageOptions[1];
  var pollTimer = null;
  var currentDataset = '';
  var startBusy = false;
  var reviewedDataset = '', lastPreview = null, lastDocker = null, lastStatus = {}, pendingInspection = false;
  var confirmation = document.getElementById('migrate-review-confirm');
  confirmation.addEventListener('change', function () { updateMigrationControls(lastStatus, selectedDatasetValue() !== ''); });

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function discoverCsrfToken() {
    var globalCandidates = [document.querySelector('.zfsas-workspace')?.dataset.csrf, window.csrf_token, window.CSRF_TOKEN, window.csrfToken];
    for (var i = 0; i < globalCandidates.length; i += 1) {
      if (typeof globalCandidates[i] === 'string' && globalCandidates[i].length > 0) {
        return globalCandidates[i];
      }
    }

    var selectors = ['input[name="csrf_token"]', 'input[name="csrf-token"]', 'input[name="_csrf"]', 'meta[name="csrf_token"]', 'meta[name="csrf-token"]', 'meta[name="x-csrf-token"]'];
    for (var j = 0; j < selectors.length; j += 1) {
      var node = document.querySelector(selectors[j]);
      if (!node) {
        continue;
      }
      if (node.tagName === 'META') {
        var content = node.getAttribute('content');
        if (typeof content === 'string' && content.length > 0) {
          return content;
        }
      } else if (typeof node.value === 'string' && node.value.length > 0) {
        return node.value;
      }
    }

    return '';
  }

  function requestJson(url, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.timeout = 20000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }
      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (error) {
        onError(error);
        return;
      }
      if (xhr.status < 200 || xhr.status >= 300) {
        onError(new Error((payload && payload.error) ? payload.error : ('HTTP ' + xhr.status)));
        return;
      }
      onSuccess(payload);
    };
    xhr.onerror = function () {
      onError(new Error('Network error.'));
    };
    xhr.ontimeout = function () {
      onError(new Error('Request timed out.'));
    };
    xhr.send();
  }

  function requestJsonPost(url, bodyParams, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.timeout = 45000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }
      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (error) {
        onError(error);
        return;
      }
      if (xhr.status < 200 || xhr.status >= 300) {
        onError(new Error((payload && payload.error) ? payload.error : ('HTTP ' + xhr.status)), payload);
        return;
      }
      onSuccess(payload);
    };

    xhr.onerror = function () {
      onError(new Error('Network error.'));
    };

    xhr.ontimeout = function () {
      onError(new Error('Request timed out.'));
    };

    var params = new URLSearchParams();
    Object.keys(bodyParams || {}).forEach(function (key) {
      params.append(key, bodyParams[key]);
    });

    var csrfToken = discoverCsrfToken();
    if (csrfToken !== '') {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      params.append('csrf_token', csrfToken);
    }

    xhr.send(params.toString());
  }

  function formatBytes(value) {
    var bytes = parseInt(value, 10);
    if (isNaN(bytes) || bytes < 0) {
      return '-';
    }
    if (bytes < 1024) {
      return bytes + ' B';
    }
    var units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    var size = bytes / 1024;
    var unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
      size /= 1024;
      unitIndex += 1;
    }
    return size.toFixed(size >= 100 ? 0 : 1) + ' ' + units[unitIndex];
  }

  function extractMarkedJson(raw) {
    var beginMarker = 'ZFSAS_JSON_BEGIN';
    var endMarker = 'ZFSAS_JSON_END';
    var start = raw.indexOf(beginMarker);
    if (start === -1) {
      return null;
    }
    var contentStart = start + beginMarker.length;
    var end = raw.indexOf(endMarker, contentStart);
    if (end === -1 || end <= contentStart) {
      return null;
    }
    return raw.slice(contentStart, end).trim();
  }

  function isExpectedJsonPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      return false;
    }
    if (!Object.prototype.hasOwnProperty.call(payload, 'ok') || typeof payload.ok !== 'boolean') {
      return false;
    }
    return true;
  }

  function parsePossiblyWrappedJson(rawText) {
    var raw = String(rawText == null ? '' : rawText).trim();
    var parseError = null;

    if (raw === '') {
      throw new Error('Empty response.');
    }

    if (raw.charAt(0) === '<') {
      throw new Error('Response was wrapped by the web UI or theme. Reload the page and try again.');
    }

    var marked = extractMarkedJson(raw);
    if (marked !== null) {
      var markedPayload = JSON.parse(marked);
      if (isExpectedJsonPayload(markedPayload)) {
        return markedPayload;
      }
      parseError = new Error('Unexpected JSON response shape.');
    }

    try {
      var directPayload = JSON.parse(raw);
      if (isExpectedJsonPayload(directPayload)) {
        return directPayload;
      }
      parseError = new Error('Unexpected JSON response shape.');
    } catch (error) {
      parseError = error;
    }

    var start = raw.indexOf('{');
    var end = raw.lastIndexOf('}');
    if (start !== -1 && end > start) {
      try {
        var candidatePayload = JSON.parse(raw.slice(start, end + 1));
        if (isExpectedJsonPayload(candidatePayload)) {
          return candidatePayload;
        }
      } catch (candidateError) {
        parseError = parseError || candidateError;
      }
    }

    throw parseError || new Error('Invalid JSON response.');
  }

  function progressHtml(percent) {
    var value = parseInt(percent, 10);
    if (isNaN(value) || value < 0) {
      value = 0;
    }
    if (value > 100) {
      value = 100;
    }
    return ''
      + '<div class="zfsas-progress">'
      + '<div class="zfsas-progress-track"><div class="zfsas-progress-fill" style="width:' + value + '%;"></div></div>'
      + '<div class="zfsas-progress-text">' + value + '%</div>'
      + '</div>';
  }

  function stateChip(label, kind) {
    var classes = 'zfsas-chip';
    if (kind === 'warn') {
      classes += ' warn';
    } else if (kind === 'error') {
      classes += ' error';
    }
    return '<span class="' + classes + '">' + escapeHtml(label) + '</span>';
  }

  function renderFeedback(message, kind) {
    var el = byId('migrate_feedback');
    if (!el) {
      return;
    }
    if (!message) {
      el.innerHTML = '';
      return;
    }
    var cls = 'zfsas-alert zfsas-alert-info';
    if (kind === 'error') {
      cls = 'zfsas-alert zfsas-alert-error';
    } else if (kind === 'warn') {
      cls = 'zfsas-alert zfsas-alert-warn';
    }
    el.innerHTML = '<div class="' + cls + '">' + escapeHtml(message) + '</div>';
  }

  function renderPageStatus(message, isError) {
    var el = byId('migrate_page_status');
    if (!el) {
      return;
    }
    el.textContent = message;
    el.classList.toggle('error', !!isError);
  }

  function normalizeStateLabel(value) {
    if (!value) {
      return 'Idle';
    }
    return String(value).replace(/_/g, ' ').replace(/\b\w/g, function (letter) {
      return letter.toUpperCase();
    });
  }

  function selectedDatasetValue() {
    var el = byId('migrate_dataset');
    return el ? String(el.value || '') : '';
  }

  function updateMigrationControls(status, hasSelectedDataset) {
    var startButton = byId('migrate_start');
    var previewButton = byId('migrate_preview');
    var isRunning = !!(status && status.isActive);
    var isInterrupted = !!(status && status.isStale);

    if (startButton) {
      startButton.disabled = startBusy || !hasSelectedDataset || isRunning || isInterrupted || reviewedDataset !== selectedDatasetValue() || !confirmation.checked;
      if (isRunning) {
        startButton.title = 'A dataset migration is already running.';
      } else if (isInterrupted) {
        startButton.title = 'The previous migration is interrupted. Review the live log/recovery state before starting again.';
      } else if (!hasSelectedDataset) {
        startButton.title = 'Choose a dataset before starting.';
      } else {
        startButton.title = '';
      }
    }

    if (previewButton) {
      previewButton.disabled = !hasSelectedDataset;
      previewButton.title = hasSelectedDataset ? '' : 'Choose a dataset before previewing.';
    }
  }

  function statusMatchesSelectedDataset(status) {
    var statusDataset = status && status.DATASET ? String(status.DATASET) : '';
    var selected = selectedDatasetValue();
    return statusDataset !== '' && selected !== '' && statusDataset === selected;
  }

  function renderSourceNotice(id, message, kind) {
    var el = byId(id);
    if (!el) {
      return;
    }
    if (!message) {
      el.innerHTML = '';
      return;
    }
    el.innerHTML = '<span class="zfsas-chip' + (kind === 'warn' ? ' warn' : '') + '">' + escapeHtml(message) + '</span>';
  }

  function renderFolderSourceNotice(preview, status, usingLiveRows) {
    var activeDataset = status && status.DATASET ? String(status.DATASET) : '';
    var selected = selectedDatasetValue();
    if (usingLiveRows) {
      renderSourceNotice('migrate_folder_source_notice', 'Showing live worker folder state for active dataset ' + activeDataset + '.', '');
      return;
    }
    if (preview && selected !== '') {
      if (activeDataset !== '' && activeDataset !== selected) {
        renderSourceNotice('migrate_folder_source_notice', 'Showing refreshed preview rows for selected dataset ' + selected + '. Selected dataset ' + selected + ' differs from the active migration dataset ' + activeDataset + '.', 'warn');
        return;
      }
      renderSourceNotice('migrate_folder_source_notice', 'Showing refreshed preview rows for selected dataset ' + selected + '.', '');
      return;
    }
    renderSourceNotice('migrate_folder_source_notice', '', '');
  }

  function renderContainerSourceNotice(docker, status, usingLiveRows) {
    var activeDataset = status && status.DATASET ? String(status.DATASET) : '';
    var selected = selectedDatasetValue();
    if (usingLiveRows) {
      if (selected !== '' && activeDataset !== '' && activeDataset !== selected) {
        renderSourceNotice('migrate_container_source_notice', 'Showing live worker container state for active dataset ' + activeDataset + '. Active migration dataset ' + activeDataset + ' differs from the selected dataset ' + selected + '.', 'warn');
        return;
      }
      renderSourceNotice('migrate_container_source_notice', 'Showing live worker container state for active dataset ' + activeDataset + '.', '');
      return;
    }
    if (docker && selected !== '') {
      renderSourceNotice('migrate_container_source_notice', 'Showing Docker preflight for the selected dataset ' + selected + '.', '');
      return;
    }
    renderSourceNotice('migrate_container_source_notice', '', '');
  }

  function populateDatasetSelect(datasets, preferredValue) {
    var el = byId('migrate_dataset');
    if (!el) {
      return;
    }

    var currentValue = preferredValue || el.value || '';
    var html = '<option value="">Select a dataset</option>';
    (datasets || []).forEach(function (row) {
      html += '<option value="' + escapeHtml(row.dataset || '') + '">' + escapeHtml((row.dataset || '') + ' (' + (row.mountpoint || '') + ')') + '</option>';
    });
    el.innerHTML = html;

    if (currentValue !== '') {
      el.value = currentValue;
      if (el.value !== currentValue) {
        el.value = '';
      }
    }
  }

  function renderSummary(status) {
    var state = status && status.STATE ? status.STATE : '';
    var currentMessage = status && status.MESSAGE ? status.MESSAGE : '';
    var currentFolder = status && status.CURRENT_FOLDER ? status.CURRENT_FOLDER : '-';
    var totalFolders = parseInt(status && status.TOTAL_FOLDERS ? status.TOTAL_FOLDERS : '0', 10);
    var completedFolders = parseInt(status && status.COMPLETED_FOLDERS ? status.COMPLETED_FOLDERS : '0', 10);
    if (isNaN(totalFolders)) {
      totalFolders = 0;
    }
    if (isNaN(completedFolders)) {
      completedFolders = 0;
    }

    byId('summary_state').textContent = normalizeStateLabel(state || 'idle');
    byId('summary_dataset').textContent = status && status.DATASET ? status.DATASET : '-';
    byId('summary_folders').textContent = completedFolders + ' / ' + totalFolders;
    byId('summary_step').textContent = status && status.CURRENT_STEP ? normalizeStateLabel(status.CURRENT_STEP) : '-';
    byId('summary_overall_progress').innerHTML = progressHtml(status && status.OVERALL_PERCENT ? status.OVERALL_PERCENT : 0);
    byId('summary_folder_progress').innerHTML = progressHtml(status && status.CURRENT_FOLDER_PERCENT ? status.CURRENT_FOLDER_PERCENT : 0);

    var messageEl = byId('summary_message');
    if (currentMessage) {
      messageEl.style.display = '';
      messageEl.textContent = currentFolder !== '-' && currentMessage.indexOf(currentFolder) === -1 ? (currentFolder + ': ' + currentMessage) : currentMessage;
    } else {
      messageEl.style.display = 'none';
      messageEl.textContent = '';
    }

    var waitingEl = byId('migrate_waiting_notice');
    if (status && status.isStale) {
      waitingEl.innerHTML = '<div class="zfsas-alert zfsas-alert-error">Dataset migrator worker stopped before it finished. Review the live log, then restart the migration or recovery if needed.</div>';
    } else if (status && String(status.WAITING_FOR_SPACE || '0') === '1') {
      waitingEl.innerHTML = '<div class="zfsas-alert zfsas-alert-warn">Free space is too low for <strong>'
        + escapeHtml(status.WAITING_LABEL || 'the current folder')
        + '</strong>. Free up space on the destination pool and the migration will continue automatically. Required: '
        + escapeHtml(formatBytes(status.WAITING_REQUIRED_BYTES || 0))
        + ', available now: '
        + escapeHtml(formatBytes(status.WAITING_AVAILABLE_BYTES || 0))
        + '.</div>';
    } else {
      waitingEl.innerHTML = '';
    }
  }

  function renderFolderRows(preview, status) {
    var el = byId('migrate_folder_rows');
    if (!el) {
      return;
    }

    var useStatusRows = statusMatchesSelectedDataset(status) && (status.isActive || status.isStale) && Array.isArray(status.folders);
    var rows = [];

    if (useStatusRows) {
      rows = status.folders.map(function (row) {
        return {
          name: row.name,
          targetDataset: row.targetDataset,
          sizeBytes: row.sizeBytes,
          state: row.state,
          message: row.message,
          progressPercent: row.progressPercent
        };
      });
    } else if (preview && Array.isArray(preview.folders)) {
      rows = preview.folders.map(function (row) {
        return {
          name: row.name,
          targetDataset: row.targetDataset,
          sizeBytes: row.sizeBytes,
          state: row.state,
          message: row.message,
          progressPercent: row.eligible ? 0 : 100
        };
      });
    }

    renderFolderSourceNotice(preview, status, useStatusRows);

    if (useStatusRows && !rows.length) {
      el.innerHTML = '<tr><td colspan="6" class="zfsas-dm-help">Live worker has not reported folder rows yet.</td></tr>';
      return;
    }

    if (!rows.length) {
      el.innerHTML = '<tr><td colspan="6" class="zfsas-dm-help">No top-level folders to show for the selected dataset.</td></tr>';
      return;
    }

    var html = '';
    rows.forEach(function (row) {
      var state = String(row.state || 'unknown');
      var chipKind = state === 'failed' ? 'error' : ((state === 'eligible' || state === 'complete') ? '' : 'warn');
      html += '<tr>';
      html += '<td><code>' + escapeHtml(row.name || '') + '</code></td>';
      html += '<td><code>' + escapeHtml(row.targetDataset || '') + '</code></td>';
      html += '<td>' + escapeHtml(formatBytes(row.sizeBytes || 0)) + '</td>';
      html += '<td>' + stateChip(normalizeStateLabel(state), chipKind) + '</td>';
      html += '<td>' + progressHtml(row.progressPercent || 0) + '</td>';
      html += '<td>' + escapeHtml(row.message || '') + '</td>';
      html += '</tr>';
    });
    el.innerHTML = html;
  }

  function renderContainerRows(docker, status) {
    var el = byId('migrate_container_rows');
    if (!el) {
      return;
    }

    var html = '';
    var rows = [];
    var useStatusRows = statusMatchesSelectedDataset(status) && (status.isActive || status.isStale) && Array.isArray(status.containers);

    if (useStatusRows) {
      rows = status.containers.map(function (row) {
        return {
          name: row.name,
          restartName: row.restartName,
          restartMax: row.restartMax,
          startState: row.startState,
          note: row.lastError || ((String(row.policyDisabled || '0') === '1') ? 'Restart policy temporarily forced to no during migration.' : '')
        };
      });
    } else if (docker && Array.isArray(docker.runningContainers) && docker.runningContainers.length > 0) {
      rows = docker.runningContainers.map(function (row) {
        return {
          name: row.name,
          restartName: row.restartName,
          restartMax: row.restartMax,
          startState: 'running_before_start',
          note: (row.restartName && row.restartName !== 'no') ? 'Tool will temporarily disable this restart policy while the migration is active.' : 'Will be stopped if it is still running when the migration starts.'
        };
      });
    }

    renderContainerSourceNotice(docker, status, useStatusRows);

    if (useStatusRows && !rows.length) {
      el.innerHTML = '<tr><td colspan="4" class="zfsas-dm-help">Live worker has not reported container rows yet.</td></tr>';
      return;
    }

    if (!rows.length) {
      el.innerHTML = '<tr><td colspan="4" class="zfsas-dm-help">No running containers are currently reported.</td></tr>';
      return;
    }

    rows.forEach(function (row) {
      var policy = row.restartName || 'no';
      if (policy === 'on-failure' && row.restartMax && row.restartMax !== '0') {
        policy += ':' + row.restartMax;
      }
      var state = String(row.startState || 'idle');
      var kind = (state === 'failed' || state === 'retry_failed') ? 'error' : ((state === 'running_before_start' || state === 'retry_pending') ? 'warn' : '');
      html += '<tr>';
      html += '<td><code>' + escapeHtml(row.name || '') + '</code></td>';
      html += '<td>' + stateChip(policy, policy === 'no' ? '' : 'warn') + '</td>';
      html += '<td>' + stateChip(normalizeStateLabel(state), kind) + '</td>';
      html += '<td>' + escapeHtml(row.note || '') + '</td>';
      html += '</tr>';
    });
    el.innerHTML = html;
  }

  function renderLog(lines) {
    var el = byId('migrate_log');
    if (!el) {
      return;
    }
    if (!Array.isArray(lines) || lines.length === 0) {
      el.textContent = 'No log output yet.';
      return;
    }
    el.textContent = lines.join('\n');
  }

  var statusBusy = false;
  var statusGeneration = 0;
  function refreshStatus(inspect) {
    if (inspect === true) {
      pendingInspection = true; reviewedDataset = ''; confirmation.checked = false;
      updateMigrationControls(lastStatus, selectedDatasetValue() !== '');
    }
    if (statusBusy || document.hidden) { return; }
    inspect = pendingInspection; pendingInspection = false;
    statusBusy = true;
    var generation = statusGeneration;
    var dataset = selectedDatasetValue();
    currentDataset = dataset;
    renderPageStatus('Refreshing dataset migrator status...', false);
    requestJson(
      statusUrl + '?dataset=' + encodeURIComponent(dataset) + (inspect ? '' : '&mode=runtime') + '&_=' + Date.now(),
      function (payload) {
        statusBusy = false;
        if (generation !== statusGeneration || dataset !== selectedDatasetValue()) { refreshStatus(); return; }
        var status = payload.status || {};
        if (status.isActive || lastStatus.isActive) { reviewedDataset = ''; confirmation.checked = false; }
        lastStatus = status;
        var hasSelectedDataset = (currentDataset || payload.selectedDataset || selectedDatasetValue()) !== '';
        if (Array.isArray(payload.datasets)) populateDatasetSelect(payload.datasets, currentDataset || payload.selectedDataset || status.DATASET || '');
        if (inspect) { lastPreview = payload.preview; lastDocker = payload.docker; reviewedDataset = payload.preview && !payload.previewError ? dataset : ''; confirmation.checked = false; }
        hasSelectedDataset = selectedDatasetValue() !== '';
        renderSummary(status);
        renderFolderRows(lastPreview, status);
        renderContainerRows(lastDocker, status);
        renderLog(payload.logTail || []);
        updateMigrationControls(status, hasSelectedDataset);

        if (payload.datasetError) {
          renderFeedback(payload.datasetError, 'error');
        } else if (payload.previewError && currentDataset !== '') {
          renderFeedback(payload.previewError, 'error');
        } else if (payload.docker && payload.docker.error) {
          renderFeedback(payload.docker.error, 'warn');
        } else {
          renderFeedback('', '');
        }

        if (status && status.isStale) {
          renderPageStatus('Dataset migrator worker stopped before it finished.', true);
        } else if (status && status.isActive && String(status.WAITING_FOR_SPACE || '0') === '1') {
          renderPageStatus('Dataset migration is waiting for free space before touching the next folder.', false);
        } else if (status && status.isActive) {
          renderPageStatus('Dataset migration is running in the background.', false);
        } else {
          renderPageStatus('Dataset migrator is ready.', false);
        }
        if (pendingInspection) refreshStatus();
      },
      function (error) {
        statusBusy = false;
        if (generation !== statusGeneration || dataset !== selectedDatasetValue()) { refreshStatus(); return; }
        renderPageStatus('Dataset migrator refresh failed: ' + error.message, true);
      }
    );
  }

  function startPolling() {
    if (pollTimer !== null) {
      window.clearInterval(pollTimer);
    }
    refreshStatus(true);
    pollTimer = window.setInterval(refreshStatus, 10000);
  }

  var datasetSelect = byId('migrate_dataset');
  if (datasetSelect) {
    datasetSelect.addEventListener('change', function () {
      statusGeneration++;
      currentDataset = datasetSelect.value || '';
      reviewedDataset = ''; lastPreview = null; lastDocker = null; confirmation.checked = false;
      updateMigrationControls(lastStatus, currentDataset !== '');
      refreshStatus();
    });
  }

  var refreshButton = byId('migrate_refresh');
  if (refreshButton) {
    refreshButton.addEventListener('click', function () {
      refreshStatus();
    });
  }

  var previewButton = byId('migrate_preview');
  if (previewButton) {
    previewButton.addEventListener('click', function () {
      renderPageStatus('Previewing top-level folders...', false);
      refreshStatus(true);
    });
  }

  var startButton = byId('migrate_start');
  if (startButton) {
    startButton.addEventListener('click', function () {
      var dataset = selectedDatasetValue();
      if (!dataset || startBusy || reviewedDataset !== dataset || !confirmation.checked) {
        if (!dataset) {
          renderFeedback('Choose a dataset first.', 'warn');
        }
        return;
      }

      startBusy = true;
      startButton.disabled = true;
      renderPageStatus('Starting dataset migration...', false);
      renderFeedback('', '');

      requestJsonPost(
        actionUrl,
        {action: 'start', dataset: dataset},
        function (payload) {
          startBusy = false; reviewedDataset = ''; confirmation.checked = false;
          updateMigrationControls(lastStatus, selectedDatasetValue() !== '');
          renderFeedback(payload && payload.message ? payload.message : 'Dataset migration started.', 'info');
          byId('migration-monitor').scrollIntoView({block:'start'});
          refreshStatus();
        },
        function (error, payload) {
          startBusy = false;
          updateMigrationControls({}, selectedDatasetValue() !== '');
          renderFeedback((payload && payload.error) ? payload.error : error.message, 'error');
          renderPageStatus('Dataset migration did not start.', true);
        }
      );
    });
  }

  startPolling();
})();

})();
