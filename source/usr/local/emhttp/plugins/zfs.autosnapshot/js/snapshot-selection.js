(function (root) {
  'use strict';
  class SnapshotSelection {
    constructor() { this.items = new Map(); this.anchor = null; this.generation = 0; this.dataset = ''; }
    context(dataset) { this.dataset = dataset; this.generation++; this.clear(); }
    clear() { this.items.clear(); this.anchor = null; }
    sortChanged() { this.anchor = null; this.generation++; }
    stamp() { return {dataset: this.dataset, generation: this.generation}; }
    accepts(stamp) { return stamp.dataset === this.dataset && stamp.generation === this.generation; }
    selectable(row) { return row.metadataComplete !== false && !row.pendingDelete && !row.pendingAction; }
    toggle(rows, index, checked, shift) {
      var anchorIndex = rows.findIndex(row => row.identity === this.anchor);
      var from = shift && anchorIndex >= 0 ? Math.min(anchorIndex, index) : index;
      var to = shift && anchorIndex >= 0 ? Math.max(anchorIndex, index) : index;
      for (var i = from; i <= to; i++) {
        if (!this.selectable(rows[i])) continue;
        if (checked) this.items.set(rows[i].identity, rows[i]); else this.items.delete(rows[i].identity);
      }
      this.anchor = rows[index].identity;
    }
    page(rows, checked) {
      rows.forEach(row => { if (this.selectable(row)) { if (checked) this.items.set(row.identity, row); else this.items.delete(row.identity); } });
      this.anchor = null;
    }
    capture(rows) { rows.forEach(row => { if (this.selectable(row)) this.items.set(row.identity, row); }); this.anchor = null; }
    refresh(rows) { rows.forEach(row => { if (this.items.has(row.identity)) this.items.set(row.identity, row); }); }
    header(rows) {
      var eligible = rows.filter(row => this.selectable(row));
      var selected = eligible.filter(row => this.items.has(row.identity)).length;
      return {checked: eligible.length > 0 && selected === eligible.length, indeterminate: selected > 0 && selected < eligible.length};
    }
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = SnapshotSelection;
  else root.SnapshotSelection = SnapshotSelection;
}(typeof window === 'undefined' ? globalThis : window));
