/* Farbschema vor dem Rendern setzen (verhindert Aufblitzen). Reihenfolge: gespeicherte Wahl > Systemeinstellung. */
(function () {
  var mode = null;
  try { mode = localStorage.getItem('vp-theme'); } catch (e) { /* Speicher gesperrt */ }
  var dark = mode ? mode === 'dark' : window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.classList.toggle('dark', dark);
})();
