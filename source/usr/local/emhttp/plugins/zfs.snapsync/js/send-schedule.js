(() => {
  'use strict';
  const body = document.getElementById('zfsas_send_jobs_body');
  if (!body) return;
  const specs = JSON.parse(document.getElementById('send-schedule-specs')?.textContent || '{}');
  const pending = new Map();
  function controls(parent, prefix, index, spec = {}) {
    const name = field => prefix === 'new' ? 'new_job_' + field : 'job_' + field + '[' + index + ']';
    const box = document.createElement('div'); box.className = 'zfsas-send-help';
    const timeLabel = document.createElement('label'); timeLabel.textContent = 'Daily / weekly start time ';
    const time = document.createElement('input'); time.type = 'time'; time.name = name('time');
    time.value = String(spec.hour || 0).padStart(2, '0') + ':' + String(spec.minute || 0).padStart(2, '0');
    timeLabel.append(time); box.append(timeLabel);
    const dayLabel = document.createElement('label'); dayLabel.textContent = ' Weekly day ';
    const day = document.createElement('select'); day.name = name('day');
    ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'].forEach((text, i) => day.add(new Option(text, String(i))));
    day.value = String(spec.day || 0); dayLabel.append(day); box.append(dayLabel);
    if (spec.legacy) {
      const label = document.createElement('label'), convert = document.createElement('input');
      convert.type = 'checkbox'; convert.name = name('convert'); convert.value = '1';
      label.append(convert, ' Convert this legacy schedule on Save'); box.append(document.createElement('br'), label);
    }
    const output = document.createElement('p'); output.setAttribute('role', 'status'); box.append(output); parent.append(box);
    return {time, day, output, box};
  }
  function attach(row) {
    const frequency = row.querySelector('[name^="job_frequency["]');
    if (!frequency || row.dataset.scheduleReady) return;
    row.dataset.scheduleReady = '1';
    const index = frequency.name.match(/\[([^\]]+)\]/)[1];
    const id = row.querySelector('[name^="job_id["]')?.value || '';
    const ui = controls(frequency.parentElement, 'job', index, specs[id]);
    if (row.dataset.scheduleTime) ui.time.value = row.dataset.scheduleTime;
    if (row.dataset.scheduleDay) ui.day.value = row.dataset.scheduleDay;
    let generation = 0, controller, timer;
    async function preview() {
      if (document.hidden || !row.isConnected) return;
      const mine = ++generation;
      controller?.abort(); const current = new AbortController(); controller = current;
      const timeout = setTimeout(() => current.abort(), 5000);
      const params = new URLSearchParams({kind:'send', job_id:id, frequency:frequency.value, time:ui.time.value, day:ui.day.value,
        convert:row.querySelector('[name^="job_convert["]')?.checked ? '1' : '0'});
      try {
        const response = await fetch('/plugins/zfs.snapsync/php/schedule-preview.php?' + params, {cache:'no-store', signal:current.signal});
        const text = await response.text(), match = text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
        const data = JSON.parse(match ? match[1] : text);
        if (mine !== generation) return;
        if (!data.ok) throw new Error(data.error || 'Preview unavailable.');
        ui.output.textContent = (data.spec.legacy ? 'Preserved legacy ' + data.spec.seconds / 3600 + '-hour local alignment. ' : '')
          + 'Next run: ' + (data.nextScheduledText || 'none') + (data.spec.kind === 'interval' ? ' (preview assumes Save now).' : '');
      } catch (error) { if (mine === generation && error.name !== 'AbortError') ui.output.textContent = error.message; }
      finally { clearTimeout(timeout); }
    }
    function changed() { clearTimeout(timer); timer = setTimeout(preview, 250); }
    row.addEventListener('input', changed); row.addEventListener('change', changed);
    pending.set(row, {refresh:preview, stop:() => { ++generation; controller?.abort(); clearTimeout(timer); }});
    preview();
  }
  const newFrequency = document.getElementById('new_job_frequency');
  if (newFrequency) controls(newFrequency.parentElement, 'new', '');
  body.querySelectorAll('tr').forEach(attach);
  new MutationObserver(() => {
    body.querySelectorAll('tr').forEach(attach);
    for (const [row, request] of pending) if (!row.isConnected) { request.stop(); pending.delete(row); }
  }).observe(body, {childList:true});
  document.addEventListener('visibilitychange', () => {
    for (const request of pending.values()) document.hidden ? request.stop() : request.refresh();
  });
})();
