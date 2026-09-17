(() => {
  'use strict';
  const root = document.querySelector('.zfsas-workspace');
  if (!root) return;
  function theme() {
    const host = getComputedStyle(document.body);
    const background = host.backgroundColor;
    const rgb = (background.match(/[\d.]+/g) || []).map(Number);
    const transparent = !rgb.length || (rgb.length === 4 && rgb[3] === 0);
    const dark = transparent ? matchMedia('(prefers-color-scheme: dark)').matches : (rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722) < 128;
    const values = dark ? {'bg':'#151b23','surface':'#1c2530','text':'#e4eaf2','muted':'#a5b4c7','border':'#344254','accent':'#7cb2ff','soft':'#24364d','danger':'#ff9b92','input':'#17212c'} : {'bg':'#f5f7fa','surface':'#ffffff','text':'#202b3a','muted':'#64748b','border':'#dce3ec','accent':'#2563b4','soft':'#edf3fc','danger':'#b42318','input':'#ffffff'};
    const map = {bg:'--body-background',surface:'--background-color',text:'--text-color',border:'--border-color',input:'--input-background-color'};
    Object.entries(values).forEach(([key,value]) => root.style.setProperty('--ui-'+key, host.getPropertyValue(map[key] || '--zfsas-unused').trim() || value));
    root.dataset.theme = dark ? 'dark' : 'light';
  }
  theme();
  new MutationObserver(theme).observe(document.body, {attributes:true, attributeFilter:['class','style','data-theme']});
  new MutationObserver(theme).observe(document.documentElement, {attributes:true, attributeFilter:['class','style','data-theme']});
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', theme);
  root.querySelector('.ui-menu-toggle').addEventListener('click', event => {
    const open = event.currentTarget.getAttribute('aria-expanded') !== 'true';
    event.currentTarget.setAttribute('aria-expanded',String(open));
    root.querySelector('#workspace-navigation').classList.toggle('is-open',open);
  });
  window.ZfsasUI = {
    notice(message, error=false) { const node=root.querySelector('#workspace-notice'); node.textContent=message; node.className=message ? 'ui-notice'+(error?' error':'') : ''; },
    open(dialog, trigger=document.activeElement) { dialog._trigger=trigger; dialog.showModal(); },
    close(dialog) { dialog.close(); },
    escape(value) { const node=document.createElement('span'); node.textContent=String(value ?? ''); return node.innerHTML; }
  };
  root.addEventListener('click', event => {
    const close=event.target.closest('[data-close-dialog]'); if(close) close.closest('dialog').close();
  });
  // Native dialogs provide keyboard trapping and Escape; restore the invoking
  // control explicitly, including when its list was refreshed while open.
  root.addEventListener('close', event => { if(event.target.tagName==='DIALOG' && event.target._trigger?.isConnected) event.target._trigger.focus(); },true);
  root.addEventListener('click', event => {
    if(!event.target.closest('#manual_run,#run_send_now')) return;
    const form=event.target.closest('form');
    if(form?.dataset.dirty==='true') { event.preventDefault(); event.stopImmediatePropagation(); ZfsasUI.notice('Save or discard your changes before Run Now. It uses saved settings.',true); }
  },true);
  root.addEventListener('invalid', event => {
    const field=event.target;
    for(let parent=field.parentElement; parent && parent!==root; parent=parent.parentElement) {
      if(parent.tagName==='DETAILS') parent.open=true;
      if(parent.tagName==='DIALOG' && !parent.open) ZfsasUI.open(parent);
    }
  },true);
})();
