(() => {
  'use strict';
  const root = document.getElementById('auto-coordinator-status');
  if (!root) return;
  const text = root.querySelector('[data-status]'), cancel = root.querySelector('[data-cancel]'), resume = root.querySelector('[data-resume]');
  let current = null, generation = 0, timer = null, controller = null, busy = false;
  async function json(url, options) {
    const response = await fetch(url, options), body = await response.text();
    const match = body.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
    const data = JSON.parse(match ? match[1] : body);
    if (!response.ok || !data.ok) throw new Error(data.error || 'Coordinator request failed.');
    return data;
  }
  async function refresh() {
    if (document.hidden || busy) return;
    clearTimeout(timer);
    const mine = ++generation;
    if (controller) controller.abort();
    const requestController = new AbortController(); controller = requestController;
    const timeout = setTimeout(() => requestController.abort(), 5000);
    try {
      const data = await json('/plugins/zfs.snapsync/php/coordinator-status.php', {cache: 'no-store', signal: requestController.signal});
      if (mine !== generation) return;
      current = (data.runs || []).find(run => (run.kinds || []).includes('auto') && !['complete', 'failed', 'canceled'].includes(run.state)) || null;
      cancel.disabled = !current || current.state === 'canceling';
      resume.disabled = !data.autoPaused || (current && current.state === 'canceling');
      text.textContent = current ? (current.state === 'canceling' ? 'Cancellation saved; verifying worker shutdown.' : 'Run ' + current.id + ': ' + current.state + (current.blockedReasons?.length ? ' — waiting: ' + current.blockedReasons.join(', ') : '') + (current.nextRetry ? ' — next attempt ' + new Date(current.nextRetry * 1000).toLocaleString() : ''))
        : data.autoPaused ? 'Schedule paused until Resume.' : data.available ? 'No active Auto Snapshot run.' : (data.message || 'Coordinator is unavailable.');
    } catch (error) { if (mine === generation && error.name !== 'AbortError') text.textContent = error.message; }
    finally { clearTimeout(timeout); if (!document.hidden && mine === generation) timer = setTimeout(refresh, current ? 2000 : 10000); }
  }
  async function action(name) {
    if (busy) return;
    busy = true; ++generation; if (controller) controller.abort(); clearTimeout(timer);
    cancel.disabled = true; resume.disabled = true;
    const csrf = document.querySelector('input[name="csrf_token"]')?.value || window.csrf_token || '';
    const requestController = new AbortController(), timeout = setTimeout(() => requestController.abort(), 10000);
    try {
      await json('/plugins/zfs.snapsync/php/coordinator-action.php', {method: 'POST', signal: requestController.signal,
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf},
        body: new URLSearchParams({action: name, run_id: current?.id || '', csrf_token: csrf})});
    } catch (error) { text.textContent = error.message; }
    finally { clearTimeout(timeout); busy = false; timer = setTimeout(refresh, 500); }
  }
  cancel.addEventListener('click', () => action('cancel'));
  resume.addEventListener('click', () => action('resume'));
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { ++generation; if (controller) controller.abort(); clearTimeout(timer); }
    else refresh();
  });
  refresh();
})();
