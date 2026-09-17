(() => {
  'use strict';
  const output = document.getElementById('schedule-next-preview');
  const mode = document.getElementById('schedule_mode');
  if (!output || !mode || !mode.form) return;
  const form = mode.form;
  let generation = 0, controller = null, timer = null;
  async function preview() {
    if (document.hidden) return;
    const mine = ++generation;
    if (controller) controller.abort();
    const requestController = new AbortController(); controller = requestController;
    const timeout = setTimeout(() => requestController.abort(), 5000);
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(form)) {
      if (key.startsWith('schedule_') || key === 'custom_cron_schedule' || key === 'convert_schedule') params.set(key, value);
    }
    try {
      const response = await fetch('/plugins/zfs.snapsync/php/schedule-preview.php?' + params, {signal: requestController.signal, cache: 'no-store'});
      const text = await response.text();
      const match = text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
      const data = JSON.parse(match ? match[1] : text);
      if (mine !== generation) return;
      output.textContent = data.ok ? (data.nextScheduledText ? 'Next scheduled run: ' + data.nextScheduledText + (data.spec.kind === 'interval' ? ' (preview assumes Save now).' : data.spec.legacy ? ' Legacy cron alignment: ' + data.spec.expression + '.' : '') : 'Automatic runs disabled.') : data.error;
    } catch (error) {
      if (mine === generation && error.name !== 'AbortError') output.textContent = 'Schedule preview is unavailable.';
    } finally { clearTimeout(timeout); }
  }
  form.addEventListener('input', event => {
    if (!/^(schedule_|custom_cron_schedule|convert_schedule)/.test(event.target.name || '')) return;
    clearTimeout(timer); timer = setTimeout(preview, 250);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { ++generation; if (controller) controller.abort(); clearTimeout(timer); }
    else preview();
  });
  preview();
})();
