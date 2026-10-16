/* Vertragsplattform – kleine, abhängigkeitsfreie Verbesserungen. Alle Funktionen sind ohne JavaScript nutzbar
   (Progressive Enhancement); die Sicherheitsprüfungen laufen ausschliesslich serverseitig. */
(function () {
  'use strict';

  // Farbschema umschalten
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var dark = !document.documentElement.classList.contains('dark');
      document.documentElement.classList.toggle('dark', dark);
      try { localStorage.setItem('vp-theme', dark ? 'dark' : 'light'); } catch (e) { /* ignorieren */ }
    });
  });

  // Mobile Navigation
  var toggle = document.querySelector('[data-nav-toggle]');
  var nav = document.getElementById('mainnav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Bestätigungsdialog für heikle Aktionen (data-confirm)
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  // Doppelklick-Schutz: Formular nur einmal absenden
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      if (form.dataset.submitted === '1') { ev.preventDefault(); return; }
      form.dataset.submitted = '1';
      window.setTimeout(function () { form.dataset.submitted = ''; }, 8000);
    });
  });

  // Benutzermenü bei Klick ausserhalb schliessen
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.usermenu[open]').forEach(function (d) {
      if (!d.contains(ev.target)) { d.removeAttribute('open'); }
    });
  });
})();
