#!/usr/bin/env python3
"""Safety wiring contracts; browser.cjs exercises the rendered interactions."""
from pathlib import Path
root = Path(__file__).resolve().parents[2]
plugin = root / 'source/usr/local/emhttp/plugins/zfs.snapsync'
page = (plugin / 'php/views/snapshots.php').read_text()
js = (plugin / 'js/snapshot-manager.js').read_text()
selection = (plugin / 'js/snapshot-selection.js').read_text()
for control in ['dataset-search', 'select-page', 'select-matching', 'clear-selection', 'review', 'approve', 'retry-failed', 'cleanup-mode', 'page-size']:
    assert f'id="{control}"' in page, control
for contract in ['selection.accepts(stamp)', 'payload.dataset !== stamp.dataset', 'document.hidden', 'resources.has(resource)', 'AbortController', 'i += 500', 'indeterminate', 'requestedPage']:
    assert contract in js, contract
for contract in ['this.items = new Map()', 'this.selectable(rows[i])', 'this.anchor = null', 'this.items.has(row.identity)']:
    assert contract in selection, contract
assert 'EventSource' not in js
assert 'new EventSource' not in (plugin / 'php/settings.php').read_text()
assert 'new EventSource' not in (plugin / 'php/send-settings.php').read_text()
print('PASS: Snapshot Manager bounded polling, identity guards, selection and review wiring')
