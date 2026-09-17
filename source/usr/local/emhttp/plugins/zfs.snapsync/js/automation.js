(() => {
const pageOptions = JSON.parse(document.getElementById('automation-options').textContent);

(function () {
  function byId(id) {
    return document.getElementById(id);
  }

  var logPollIntervalMs = pageOptions[0];
  var logApiUrl = pageOptions[1];
  var logStreamApiUrl = pageOptions[2];
  var runApiUrl = pageOptions[3];
  var saveApiUrl = pageOptions[4];
  var sendSettingsUrl = pageOptions[6];
  var migrateDatasetsUrl = pageOptions[7];
  var logView = 'summary';
  var logPaused = false;
  var logTimer = null;
  var logStreamSource = null;
  var logFingerprint = '';
  var saveForm = byId('zfsas_settings_form');
  var saveButton = byId('zfsas_save_btn');
  var saveButtonDefaultText = saveButton ? saveButton.textContent : 'Save Settings';
  var saveBusy = false;
  var saveSuccessTimer = null;
  var snapshotManagerFrame = byId('snapshot_manager_frame');

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function requestTargetUrl(form) {
    var action = form ? form.getAttribute('action') : '';
    if (typeof action === 'string' && action.trim() !== '') {
      return action;
    }
    return window.location.pathname + window.location.search;
  }

  function renderSaveFeedback(errors, notices) {
    var feedbackEl = byId('save_feedback');
    if (!feedbackEl) {
      return;
    }

    var html = '';
    if (Array.isArray(errors) && errors.length > 0) {
      html += '<div class="zfsas-alert zfsas-alert-error">';
      errors.forEach(function (message) {
        html += '<div>' + escapeHtml(message) + '</div>';
      });
      html += '</div>';
    }

    if (Array.isArray(notices) && notices.length > 0) {
      html += '<div class="zfsas-alert">' + notices.map(escapeHtml).join('<br>') + '</div>';
    }
    feedbackEl.innerHTML = html;
  }

  function clearSaveButtonSuccessState() {
    if (!saveButton) {
      return;
    }

    if (saveSuccessTimer !== null) {
      window.clearTimeout(saveSuccessTimer);
      saveSuccessTimer = null;
    }
    saveButton.textContent = saveButtonDefaultText;
  }

  function renderCompatibilityFeedback(message) {
    var feedbackEl = byId('compat_feedback');
    if (!feedbackEl) {
      return;
    }

    if (typeof message !== 'string' || message.trim() === '') {
      feedbackEl.innerHTML = '';
      return;
    }

    feedbackEl.innerHTML =
      '<div class="zfsas-alert zfsas-alert-warn">'
      + '<div>' + escapeHtml(message) + '</div>'
      + '</div>';
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

  function parsePossiblyWrappedJson(rawText) {
    var raw = String(rawText == null ? '' : rawText).trim();
    var parseError = null;

    if (raw === '') {
      throw new Error('Empty response.');
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
      var candidate = raw.slice(start, end + 1);
      try {
        var payload = JSON.parse(candidate);
        if (isExpectedJsonPayload(payload)) {
          return payload;
        }
      } catch (candidateError) {
        parseError = parseError || candidateError;
      }
    }

    throw parseError || new Error('Invalid JSON response.');
  }

  function isExpectedJsonPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
      return false;
    }

    if (!Object.prototype.hasOwnProperty.call(payload, 'ok') || typeof payload.ok !== 'boolean') {
      return false;
    }

    if (Object.prototype.hasOwnProperty.call(payload, 'errors') && !Array.isArray(payload.errors)) {
      return false;
    }

    if (Object.prototype.hasOwnProperty.call(payload, 'notices') && !Array.isArray(payload.notices)) {
      return false;
    }

    return true;
  }

  function discoverCsrfToken() {
    var globalCandidates = [window.csrf_token, window.CSRF_TOKEN, window.csrfToken];
    for (var i = 0; i < globalCandidates.length; i += 1) {
      var value = globalCandidates[i];
      if (typeof value === 'string' && value.length > 0) {
        return value;
      }
    }

    var inputSelectors = [
      'input[name="csrf_token"]',
      'input[name="csrf-token"]',
      'input[name="_csrf"]'
    ];
    for (var j = 0; j < inputSelectors.length; j += 1) {
      var csrfInput = document.querySelector(inputSelectors[j]);
      if (csrfInput && typeof csrfInput.value === 'string' && csrfInput.value.length > 0) {
        return csrfInput.value;
      }
    }

    var metaSelectors = [
      'meta[name="csrf_token"]',
      'meta[name="csrf-token"]',
      'meta[name="x-csrf-token"]'
    ];
    for (var k = 0; k < metaSelectors.length; k += 1) {
      var metaTag = document.querySelector(metaSelectors[k]);
      if (metaTag) {
        var content = metaTag.getAttribute('content');
        if (typeof content === 'string' && content.length > 0) {
          return content;
        }
      }
    }

    return '';
  }

  function requestJsonFormPost(form, targetUrl, onSuccess, onError, onComplete) {
    var xhr = new XMLHttpRequest();
    var finished = false;

    function finalize() {
      if (finished) {
        return;
      }
      finished = true;
      if (typeof onComplete === 'function') {
        onComplete();
      }
    }

    xhr.open('POST', targetUrl || requestTargetUrl(form), true);
    xhr.timeout = 45000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    var formData = new FormData(form);
    var requestParams = new URLSearchParams();
    formData.forEach(function (value, key) {
      requestParams.append(key, value);
    });
    requestParams.append('ajax', 'save');

    var csrfToken = discoverCsrfToken();

    if (csrfToken !== '') {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      if (!requestParams.has('csrf_token')) {
        requestParams.append('csrf_token', csrfToken);
      }
    }

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (parseError) {
        try {
          var raw = String(xhr.responseText || '').trim();
          if (raw.charAt(0) === '<') {
            onError(new Error('Save response was wrapped by the web UI or theme. Reload the page and try again.'));
          } else {
            onError(new Error('Invalid save response.'));
          }
        } finally {
          finalize();
        }
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        try {
          onError(new Error((payload && payload.errors && payload.errors[0]) ? payload.errors[0] : ('HTTP ' + xhr.status)), payload);
        } finally {
          finalize();
        }
        return;
      }

      try {
        onSuccess(payload);
      } finally {
        finalize();
      }
    };

    xhr.onerror = function () {
      try {
        onError(new Error('Network error while saving settings.'));
      } finally {
        finalize();
      }
    };

    xhr.ontimeout = function () {
      try {
        onError(new Error('Save request timed out. The settings may still be applying; reload the page and confirm.'));
      } finally {
        finalize();
      }
    };

    xhr.onabort = function () {
      try {
        onError(new Error('Save request was interrupted. Reload the page and try again.'));
      } finally {
        finalize();
      }
    };

    xhr.send(requestParams.toString());
  }

  function setSaveButtonState(isBusy) {
    saveBusy = !!isBusy;
    if (!saveButton) {
      return;
    }

    if (saveBusy) {
      clearSaveButtonSuccessState();
      saveButton.disabled = true;
      saveButton.setAttribute('disabled', 'disabled');
      saveButton.setAttribute('aria-busy', 'true');
      saveButton.textContent = 'Saving...';
      return;
    }

    saveButton.disabled = false;
    saveButton.removeAttribute('disabled');
    saveButton.setAttribute('aria-busy', 'false');
    if (saveSuccessTimer === null) {
      saveButton.textContent = saveButtonDefaultText;
    }
    saveButton.blur();
  }

  function showSaveButtonSavedState() {
    if (!saveButton) {
      return;
    }

    clearSaveButtonSuccessState();
    saveButton.textContent = 'Saved';
    saveSuccessTimer = window.setTimeout(function () {
      clearSaveButtonSuccessState();
    }, 5000);
  }

  function pad2(value) {
    value = parseInt(value, 10);
    if (isNaN(value)) {
      value = 0;
    }
    return String(value).padStart(2, '0');
  }

  function showOnlyForMode(mode) {
    var rows = document.querySelectorAll('.zfsas-schedule-row');
    rows.forEach(function (row) {
      row.style.display = (row.getAttribute('data-mode') === mode) ? 'flex' : 'none';
      row.style.flexDirection = 'column';
    });
  }

  function previewText() {
    var mode = byId('schedule_mode').value;
    if (mode === 'disabled') {
      return 'Automatic schedule is disabled. Run manually whenever needed.';
    }

    if (mode === 'minutes') {
      var everyMinutes = parseInt(byId('schedule_every_minutes').value, 10);
      if (isNaN(everyMinutes) || everyMinutes < 1) {
        everyMinutes = 1;
      }
      if (everyMinutes === 1) {
        return 'Runs every minute.';
      }
      return 'Runs every ' + everyMinutes + ' minutes.';
    }

    if (mode === 'hourly') {
      var every = parseInt(byId('schedule_every_hours').value, 10);
      if (isNaN(every) || every < 1) {
        every = 1;
      }
      if (every === 1) {
        return 'Runs every hour at minute 00.';
      }
      return 'Runs every ' + every + ' hours at minute 00.';
    }

    if (mode === 'daily') {
      return 'Runs every day at ' + pad2(byId('schedule_daily_hour').value) + ':' + pad2(byId('schedule_daily_minute').value) + '.';
    }

    if (mode === 'weekly') {
      var dayText = byId('schedule_weekly_day').selectedOptions[0].text;
      return 'Runs every ' + dayText + ' at ' + pad2(byId('schedule_weekly_hour').value) + ':' + pad2(byId('schedule_weekly_minute').value) + '.';
    }

    return 'Runs using custom cron expression: ' + byId('custom_cron_schedule').value;
  }

  function refreshScheduleUI() {
    var mode = byId('schedule_mode').value;
    showOnlyForMode(mode);
    byId('schedule_preview').textContent = previewText();
  }

  function rowIsVisible(row) {
    return !row.classList.contains('zfsas-row-hidden');
  }

  function applyPoolFilter() {
    var poolFilter = byId('dataset_pool_filter');
    var selectedPool = poolFilter ? poolFilter.value : '__all';
    var rows = document.querySelectorAll('.zfsas-dataset-row');

    rows.forEach(function (row) {
      var rowPool = row.getAttribute('data-pool') || '';
      var query = (byId('dataset_name_filter').value || '').toLowerCase();
      var name = row.querySelector('input[type="hidden"]').value.toLowerCase();
      var shouldShow = (selectedPool === '__all' || rowPool === selectedPool) && name.indexOf(query) !== -1;
      row.classList.toggle('zfsas-row-hidden', !shouldShow);
    });
  }

  function refreshDatasetCount() {
    var countLabel = byId('dataset_count');
    if (!countLabel) {
      return;
    }

    var boxes = document.querySelectorAll('.zfsas-dataset-checkbox');
    if (!boxes.length) {
      countLabel.textContent = 'No datasets available.';
      return;
    }

    var selected = 0;
    var visible = 0;
    var visibleSelected = 0;
    boxes.forEach(function (box) {
      var row = box.closest('.zfsas-dataset-row');
      var isVisible = row ? rowIsVisible(row) : true;

      if (isVisible) {
        visible++;
      }

      if (box.checked) {
        selected++;
        if (isVisible) {
          visibleSelected++;
        }
      }
    });

    countLabel.textContent = selected + ' selected of ' + boxes.length + ' datasets (' + visibleSelected + ' of ' + visible + ' shown).';
  }

  function setAllDatasetChecks(checked, visibleOnly) {
    var boxes = document.querySelectorAll('.zfsas-dataset-checkbox');
    boxes.forEach(function (box) {
      if (box.disabled) {
        box.checked = false;
        return;
      }
      if (visibleOnly) {
        var row = box.closest('.zfsas-dataset-row');
        if (row && !rowIsVisible(row)) {
          return;
        }
      }
      box.checked = checked;
    });
    refreshDatasetCount();
  }

  function setLogStatus(message, isError) {
    var statusEl = byId('log_status');
    if (!statusEl) {
      return;
    }
    statusEl.textContent = message;
    statusEl.classList.toggle('error', !!isError);
  }

  function setManualRunStatus(message, isError) {
    var statusEl = byId('manual_run_status');
    if (!statusEl) {
      return;
    }

    statusEl.textContent = message;
    statusEl.classList.toggle('error', !!isError);
  }

  function currentLogViewLabel() {
    return (logView === 'debug') ? 'debug log' : 'run summary';
  }

  function refreshLogViewControls() {
    var toggleBtn = byId('log_view_toggle');
    var linesEl = byId('log_lines');

    if (toggleBtn) {
      toggleBtn.textContent = (logView === 'debug') ? 'Show Run Summary' : 'Show Debug Log';
    }

    if (linesEl) {
      linesEl.disabled = (logView === 'summary');
    }
  }

  function buildLogApiUrl(download) {
    if (download) {
      return logApiUrl + '?download=1&_=' + Date.now();
    }

    var linesEl = byId('log_lines');
    var lines = linesEl ? parseInt(linesEl.value, 10) : 400;
    if (isNaN(lines) || lines < 50) {
      lines = 400;
    }

    var url = logApiUrl
      + '?type=' + encodeURIComponent(logView)
      + '&lines=' + encodeURIComponent(lines);

    return url + '&_=' + Date.now();
  }

  function buildLogStreamUrl() {
    var linesEl = byId('log_lines');
    var lines = linesEl ? parseInt(linesEl.value, 10) : 400;
    if (isNaN(lines) || lines < 50) {
      lines = 400;
    }

    return logStreamApiUrl
      + '?type=' + encodeURIComponent(logView)
      + '&lines=' + encodeURIComponent(lines)
      + '&_=' + Date.now();
  }

  function buildLogFingerprint(data, content) {
    var head = content.slice(0, 128);
    var tail = content.slice(-128);
    return String(data.mtime || 0)
      + ':' + String(data.size || 0)
      + ':' + String(content.length)
      + ':' + head
      + ':' + tail;
  }

  function applyLogPayload(data, forceScrollToBottom) {
    var outputEl = byId('log_output');
    if (!outputEl) {
      return;
    }

    if (!data || data.ok !== true) {
      setLogStatus('Log refresh failed: Unexpected response payload.', true);
      return;
    }

    var shouldFollowTail = !!forceScrollToBottom || (outputEl.scrollTop + outputEl.clientHeight >= outputEl.scrollHeight - 40);
    var content = '';
    if (data.unsafe) {
      content = 'Selected log file path failed safety checks and was blocked.';
    } else if (!data.exists) {
      if (logView === 'debug') {
        content = 'Debug log does not exist yet. Start a run and this view will populate.';
      } else {
        content = 'Run summary is not available yet. Start a run and this view will populate.';
      }
    } else if (!data.readable) {
      content = 'Selected log file exists but is not readable by the web UI process.';
    } else if (!data.content) {
      if (logView === 'debug') {
        content = 'Debug log is currently empty.';
      } else {
        content = 'Run summary is currently empty.';
      }
    } else {
      content = data.content;
      if (data.truncated) {
        content = '[Showing the latest portion of the selected log]\n' + content;
      }
    }

    var fingerprint = buildLogFingerprint(data, content);
    if (fingerprint !== logFingerprint || forceScrollToBottom) {
      outputEl.textContent = content;
      logFingerprint = fingerprint;
    }

    if (shouldFollowTail) {
      outputEl.scrollTop = outputEl.scrollHeight;
    }

    var prefix = logPaused ? 'Paused' : 'Live';
    setLogStatus(prefix + ' ' + currentLogViewLabel() + ' | Last refresh: ' + new Date().toLocaleTimeString(), false);
  }

  function requestJson(url, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        onError(new Error('HTTP ' + xhr.status));
        return;
      }

      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (parseError) {
        var raw = String(xhr.responseText || '').trim();
        if (raw.charAt(0) === '<') {
          onError(new Error('Session expired or security token was rejected. Reload the page and try again.'));
        } else {
          onError(new Error('Invalid JSON response.'));
        }
        return;
      }

      onSuccess(payload);
    };

    xhr.onerror = function () {
      onError(new Error('Network error.'));
    };

    xhr.send();
  }

  function requestJsonPost(url, bodyParams, onSuccess, onError) {
    bodyParams = bodyParams || {};
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.timeout = 15000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    var csrfToken = discoverCsrfToken();

    if (csrfToken !== '') {
      xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    }

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300) {
        var errorPayload = null;
        try {
          errorPayload = parsePossiblyWrappedJson(xhr.responseText);
        } catch (ignoredParseError) {
          errorPayload = null;
        }

        onError(new Error((errorPayload && errorPayload.error) ? errorPayload.error : ('HTTP ' + xhr.status)), errorPayload);
        return;
      }

      var payload;
      try {
        payload = parsePossiblyWrappedJson(xhr.responseText);
      } catch (parseError) {
        var raw = String(xhr.responseText || '').trim();
        if (raw.charAt(0) === '<') {
          onError(new Error('Session expired or security token was rejected. Reload the page and try again.'));
        } else {
          onError(new Error('Invalid JSON response.'));
        }
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

    var requestParams = new URLSearchParams();
    Object.keys(bodyParams).forEach(function (key) {
      var value = bodyParams[key];
      if (Array.isArray(value)) {
        value.forEach(function (entry) {
          requestParams.append(key, entry);
        });
        return;
      }
      if (value !== undefined && value !== null) {
        requestParams.append(key, value);
      }
    });

    if (csrfToken !== '') {
      requestParams.append('csrf_token', csrfToken);
    }

    xhr.send(requestParams.toString());
  }

  function runSaveCompatibilityProbe() {
    requestJsonPost(
      saveApiUrl,
      {probe: '1'},
      function (data) {
        if (data && data.ok === true && data.probe === true) {
          renderCompatibilityFeedback('');
          return;
        }

        renderCompatibilityFeedback('Save endpoint compatibility probe returned an unexpected result. If saving fails on this server, another plugin or theme may be altering plugin responses.');
      },
      function (error) {
        renderCompatibilityFeedback('Save endpoint compatibility probe failed: ' + error.message + ' If saving is unreliable on this server, another plugin or theme may be altering plugin responses.');
      }
    );
  }

  var logRequestBusy = false;
  var logRequestGeneration = 0;
  function fetchLiveLog(forceScrollToBottom) {
    if (logRequestBusy || document.hidden) { return; }
    var generation = logRequestGeneration;
    logRequestBusy = true;
    var outputEl = byId('log_output');
    if (!outputEl) {
      logRequestBusy = false; return;
    }

    setLogStatus((logPaused ? 'Paused' : 'Updating') + ' ' + currentLogViewLabel() + '...', false);

    requestJson(
      buildLogApiUrl(false),
      function (data) {
        logRequestBusy = false;
        if (generation === logRequestGeneration) { applyLogPayload(data, forceScrollToBottom); }
      },
      function (error) {
        logRequestBusy = false;
        if (generation === logRequestGeneration) { setLogStatus('Log refresh failed: ' + error.message, true); }
      }
    );
  }

  function stopLogPolling() {
    if (logTimer !== null) {
      clearInterval(logTimer);
      logTimer = null;
    }
  }

  function startLogPolling() {
    stopLogPolling();

    logTimer = setInterval(function () {
      if (logPaused) {
        return;
      }
      fetchLiveLog(false);
    }, logPollIntervalMs);
  }

  function stopLogStream() {
    if (logStreamSource) {
      logStreamSource.close();
      logStreamSource = null;
    }
  }

  function startLogStream() { return false; }

  function restartLogTransport(forceScrollToBottom) {
    logRequestGeneration++;
    stopLogStream();
    stopLogPolling();

    if (logPaused) {
      setLogStatus('Paused.', false);
      return;
    }

    logFingerprint = '';
    if (!startLogStream()) {
      startLogPolling();
      fetchLiveLog(!!forceScrollToBottom);
    } else if (forceScrollToBottom) {
      fetchLiveLog(true);
    }
  }

  ['schedule_mode', 'schedule_every_minutes', 'schedule_every_hours', 'schedule_daily_hour', 'schedule_daily_minute', 'schedule_weekly_day', 'schedule_weekly_hour', 'schedule_weekly_minute', 'custom_cron_schedule'].forEach(function (id) {
    var element = byId(id);
    if (element) {
      element.addEventListener('change', refreshScheduleUI);
      element.addEventListener('input', refreshScheduleUI);
    }
  });

  var selectAllBtn = byId('dataset_select_all');
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function () {
      setAllDatasetChecks(true, false);
    });
  }

  var clearAllBtn = byId('dataset_clear_all');
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', function () {
      setAllDatasetChecks(false, false);
    });
  }

  var selectVisibleBtn = byId('dataset_select_visible');
  if (selectVisibleBtn) {
    selectVisibleBtn.addEventListener('click', function () {
      setAllDatasetChecks(true, true);
    });
  }

  var clearVisibleBtn = byId('dataset_clear_visible');
  if (clearVisibleBtn) {
    clearVisibleBtn.addEventListener('click', function () {
      setAllDatasetChecks(false, true);
    });
  }

  var poolFilter = byId('dataset_pool_filter');
  if (poolFilter) {
    poolFilter.addEventListener('change', function () {
      applyPoolFilter();
      refreshDatasetCount();
    });
  }

  document.addEventListener('zfsas:datasets-ready', function () { byId('dataset-discovery-status').textContent = ''; applyPoolFilter(); refreshDatasetCount(); });
  byId('dataset_name_filter').addEventListener('input', function () { applyPoolFilter(); refreshDatasetCount(); });
  byId('async-dataset-rows').addEventListener('change', refreshDatasetCount);
  var datasetBoxes = document.querySelectorAll('.zfsas-dataset-checkbox');
  datasetBoxes.forEach(function (box) {
    if (box.disabled) {
      box.checked = false;
    }
    box.addEventListener('change', refreshDatasetCount);
  });



  var logToggleBtn = byId('log_toggle');
  if (logToggleBtn) {
    logToggleBtn.addEventListener('click', function () {
      logPaused = !logPaused;
      logToggleBtn.textContent = logPaused ? 'Resume Live View' : 'Pause Live View';
      if (!logPaused) {
        restartLogTransport(true);
      } else {
        stopLogStream();
        stopLogPolling();
        setLogStatus('Paused.', false);
      }
    });
  }

  var logViewToggleBtn = byId('log_view_toggle');
  if (logViewToggleBtn) {
    logViewToggleBtn.addEventListener('click', function () {
      logView = (logView === 'debug') ? 'summary' : 'debug';
      logFingerprint = '';
      refreshLogViewControls();
      restartLogTransport(true);
    });
  }

  var logRefreshBtn = byId('log_refresh');
  if (logRefreshBtn) {
    logRefreshBtn.addEventListener('click', function () {
      fetchLiveLog(true);
    });
  }

  var logDownloadBtn = byId('log_download');
  if (logDownloadBtn) {
    logDownloadBtn.addEventListener('click', function () {
      window.location.href = buildLogApiUrl(true);
    });
  }

  var logLinesSelect = byId('log_lines');
  if (logLinesSelect) {
    logLinesSelect.addEventListener('change', function () {
      restartLogTransport(true);
    });
  }

  var manualRunBtn = byId('manual_run');
  var openSendSettingsBtn = byId('open_send_settings');
  var openDatasetMigratorBtn = byId('open_dataset_migrator');
  var manualRunBusy = false;
  if (openSendSettingsBtn) {
    openSendSettingsBtn.addEventListener('click', function () {
      window.location.href = sendSettingsUrl;
    });
  }

  if (openDatasetMigratorBtn) {
    openDatasetMigratorBtn.addEventListener('click', function () {
      window.location.href = migrateDatasetsUrl;
    });
  }


  var manualAutoCommandId = null;
  if (manualRunBtn) {
    manualRunBtn.addEventListener('click', function () {
      if (manualRunBusy) {
        return;
      }

      manualRunBusy = true;
      manualRunBtn.disabled = true;
      setManualRunStatus('Starting manual run...', false);
      if (!manualAutoCommandId) manualAutoCommandId = 'manual-auto-' + Date.now() + '-' + Math.random().toString(16).slice(2);

      requestJsonPost(
        runApiUrl,
        {command_id: manualAutoCommandId},
        function (data) {
          manualRunBusy = false;
          manualRunBtn.disabled = false;

          if (!data || data.ok !== true) {
            setManualRunStatus('Manual run start failed: Unexpected response.', true);
            return;
          }

          manualAutoCommandId = null;
          var message = (typeof data.message === 'string' && data.message.length > 0)
            ? data.message
            : 'Manual run started.';

          setManualRunStatus(message, false);
          logPaused = false;
          if (logToggleBtn) {
            logToggleBtn.textContent = 'Pause Live View';
          }
          restartLogTransport(true);
        },
        function (error) {
          manualRunBusy = false;
          manualRunBtn.disabled = false;
          setManualRunStatus('Manual run start failed: ' + error.message, true);
        }
      );
    });
  }

  function startSave(event) {
    if (event) {
      event.preventDefault();
      if (typeof event.stopImmediatePropagation === 'function') {
        event.stopImmediatePropagation();
      }
      if (typeof event.stopPropagation === 'function') {
        event.stopPropagation();
      }
    }

    if (!saveForm) {
      renderSaveFeedback(['Save form is unavailable. Reload the page and try again.'], []);
      return;
    }

    if (saveForm && !saveForm.reportValidity()) { return; }

    if (saveBusy) {
      return;
    }

    var saveFailsafeTimer = null;
    setSaveButtonState(true);
    renderSaveFeedback([], []);

    saveFailsafeTimer = window.setTimeout(function () {
      if (!saveBusy) {
        return;
      }
      setSaveButtonState(false);
      renderSaveFeedback(['Save is taking longer than expected. Reload the page and verify whether the settings were applied.'], []);
    }, 50000);

    try {
      requestJsonFormPost(
        saveForm,
        saveForm.getAttribute('data-ajax-action') || requestTargetUrl(saveForm),
        function (data) {
          saveForm.dispatchEvent(new CustomEvent('zfsas:saved', {detail: data}));
          var notices = Array.isArray(data.notices) ? data.notices : [];
          renderSaveFeedback(data.errors || [], notices);
          if (!Array.isArray(data.errors) || data.errors.length === 0) {
            showSaveButtonSavedState();
          } else {
            clearSaveButtonSuccessState();
          }

          var cronValueEl = byId('resolved_cron_value');
          if (cronValueEl && typeof data.resolvedCron === 'string') {
            cronValueEl.textContent = data.resolvedCron;
          }

          var prefixPreviewEl = byId('prefix_preview');
          var prefixInputEl = byId('prefix');
          if (prefixPreviewEl) {
            prefixPreviewEl.textContent = (typeof data.prefix === 'string' && data.prefix.length > 0)
              ? data.prefix
              : (prefixInputEl ? prefixInputEl.value : '');
          }
        },
        function (error, payload) {
          if (payload && Array.isArray(payload.errors)) {
            renderSaveFeedback(payload.errors, payload.notices || []);
          } else {
            renderSaveFeedback([error.message], []);
          }
          clearSaveButtonSuccessState();
        },
        function () {
          if (saveFailsafeTimer !== null) {
            window.clearTimeout(saveFailsafeTimer);
          }
          setSaveButtonState(false);
        }
      );
    } catch (error) {
      if (saveFailsafeTimer !== null) {
        window.clearTimeout(saveFailsafeTimer);
      }
      setSaveButtonState(false);
      clearSaveButtonSuccessState();
      renderSaveFeedback(['Save request could not be started: ' + String(error && error.message ? error.message : error)], []);
    }
  }

  if (saveButton) {
    saveButton.addEventListener('click', startSave, true);
  }

  if (saveForm) {
    saveForm.addEventListener('submit', startSave, true);
  }

  if (saveButton && saveButton.getAttribute('data-show-saved') === '1') {
    showSaveButtonSavedState();
    saveButton.removeAttribute('data-show-saved');
  }


  applyPoolFilter();
  refreshScheduleUI();
  refreshDatasetCount();
  refreshLogViewControls();
  runSaveCompatibilityProbe();

  if (byId('log_output')) {
    restartLogTransport(true);
  }

  window.addEventListener('beforeunload', function () {
    stopLogStream();
    stopLogPolling();
  });
})();

})();
