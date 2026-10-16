/* Hilfe: Anleitungen nach Stichwort filtern (rein clientseitig, keine Daten verlassen die Seite). */
(function () {
  'use strict';
  var q = document.querySelector('[data-help-search]');
  if (!q) { return; }
  var items = Array.prototype.slice.call(document.querySelectorAll('[data-help-item]'));
  var groups = Array.prototype.slice.call(document.querySelectorAll('[data-help-group]'));
  var empty = document.querySelector('[data-help-empty]');
  q.addEventListener('input', function () {
    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;
    items.forEach(function (el) {
      var t = el.getAttribute('data-text') || '';
      var ok = words.every(function (w) { return t.indexOf(w) !== -1; });
      el.hidden = !ok;
      if (ok) { shown++; }
    });
    groups.forEach(function (g) { g.hidden = !g.querySelector('[data-help-item]:not([hidden])'); });
    if (empty) { empty.hidden = shown !== 0; }
  });
})();
