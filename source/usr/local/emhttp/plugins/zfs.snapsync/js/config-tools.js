(function () {
  'use strict';
  document.querySelectorAll('[data-config-tools]').forEach(function (panel) {
    if (panel.dataset.ready) return;
    panel.dataset.ready = '1';
    var form = panel.closest('form');
    var options = JSON.parse(panel.dataset.configTools);
    var prefix = form.elements[options.prefixField];
    var status = panel.querySelector('[data-dirty]');
    var feedback = panel.querySelector('[data-prefix-feedback]');
    function values() { return JSON.stringify(Array.from(new FormData(form).entries()).filter(function (p) { if (p[0] === 'csrf_token') return false;
        var dataset = p[0].match(/^dataset_(?:name|threshold)\[(\d+)\]$/);
        return !dataset || form.elements['dataset_selected[' + dataset[1] + ']']?.checked; }).sort(function(a,b){return a[0].localeCompare(b[0]) || String(a[1]).localeCompare(String(b[1]));})); }
    var baseline = values(), touched = false;
    form.addEventListener('input', function(){ touched = true; });
    form.addEventListener('change', function(event){ if(event.isTrusted) touched = true; });
    document.addEventListener('DOMContentLoaded', function(){ if(!touched) { baseline = values(); update(); } }, {once:true});
    function update() {
      form.dataset.dirty = String(values() !== baseline);
      status.hidden = values() === baseline;
      if (discard) discard.hidden = values() === baseline;
      status.textContent = values() === baseline ? 'All changes saved.' : 'Unsaved changes — choose Save to apply.';
      var value = prefix.value.trim(), other = options.otherPrefix;
      var conflict = !value || !other || value.indexOf(other) === 0 || other.indexOf(value) === 0;
      prefix.setCustomValidity(conflict ? 'Prefixes must differ and neither may begin with the other.' : '');
      feedback.textContent = 'Other configured prefix: ' + other + '. ' + (conflict ? 'Conflict: neither prefix may be the beginning of the other. Automatic cleanup and replication are blocked until resolved.' : 'Prefixes are separate.');
      feedback.hidden = !conflict;
      feedback.style.color = conflict ? '#b42318' : '';
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    new MutationObserver(function (changes) { if (changes.some(function (change) { return !panel.contains(change.target); })) update(); }).observe(form, {childList: true, subtree: true});
    panel.querySelector('[data-restore-tuning]').addEventListener('click', function () {
      Object.keys(options.defaults).forEach(function (name) {
        var input = form.elements[name];
        if (input) { input.value = options.defaults[name]; input.dispatchEvent(new Event('change', {bubbles: true})); }
      });
      update();
    });
    form.addEventListener('zfsas:saved', function (event) {
      if (!event.detail.saved) return;
      form.elements.config_revision.value = event.detail.revision;
      baseline = values(); update();
    });
    var discard=document.createElement('button'); discard.type='button'; discard.textContent='Discard changes';
    discard.addEventListener('click',function(){ if(values()===baseline || window.confirm('Discard unsaved changes?')) { baseline=values(); window.location.reload(); } });
    panel.appendChild(discard);
    window.addEventListener('beforeunload', function (event) {
      if (values() !== baseline) { event.preventDefault(); event.returnValue = ''; }
    });
    update();
  });
}());
