(function () {
  'use strict';
  const form = document.getElementById('interface-settings');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button'), status = document.getElementById('interface-status');
    button.disabled = true; status.textContent = 'Saving interface preference…';
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch('/plugins/zfs.snapsync/php/save-interface-settings.php', {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        body: new URLSearchParams({show_tab: form.elements.show_tab.checked ? '1' : '0', revision: form.elements.revision.value, csrf_token: document.querySelector('.zfsas-workspace').dataset.csrf})
      });
      const text = await response.text(), match = text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
      const result = JSON.parse(match ? match[1] : text);
      if (!response.ok || !result.ok) throw new Error(result.error || 'Unable to save interface settings.');
      form.elements.revision.value = result.revision;
      status.textContent = 'Saved. Reload the page to update the Unraid navigation.';
      document.getElementById('interface-reload').hidden = false;
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'Save timed out. Reload to check whether the preference was saved.' : error.message;
    } finally { clearTimeout(timer); button.disabled = false; }
  });
}());
