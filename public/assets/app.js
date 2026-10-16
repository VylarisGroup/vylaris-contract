/* Vertragsplattform – kleine, abhängigkeitsfreie Verbesserungen. Alle Funktionen sind ohne JavaScript nutzbar
   (Progressive Enhancement); die Sicherheitsprüfungen laufen ausschliesslich serverseitig. */
(function () {
  'use strict';

  // Navigation (Off-Canvas auf kleinen Bildschirmen)
  var toggle = document.querySelector('[data-nav-toggle]');
  var nav = document.getElementById('mainnav');
  function setNav(open) {
    if (!nav) { return; }
    nav.classList.toggle('is-open', open);
    document.body.classList.toggle('nav-open', open);
    if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  if (toggle && nav) {
    toggle.addEventListener('click', function () { setNav(!nav.classList.contains('is-open')); });
    document.addEventListener('click', function (ev) { if (ev.target.closest('[data-nav-close]')) { setNav(false); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setNav(false); } });
  }

  // Hinweise (Toasts): Erfolg/Info blenden sich aus, Fehler bleiben bis zum Schliessen
  function closeToast(el) {
    el.classList.add('is-leaving');
    window.setTimeout(function () { el.remove(); }, 260);
  }
  document.querySelectorAll('.toasts [data-toast]').forEach(function (t) {
    var type = t.getAttribute('data-toast');
    if (type === 'success' || type === 'info') { window.setTimeout(function () { closeToast(t); }, 6000); }
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-toast-close]');
    if (b) { closeToast(b.closest('.alert')); }
  });

  // Bestätigungsdialog für heikle Aktionen (data-confirm); ohne <dialog>-Unterstützung der Browserdialog
  var pending = null;
  var dlg = null;
  function buildDialog() {
    dlg = document.createElement('dialog');
    dlg.className = 'confirm';
    dlg.setAttribute('aria-labelledby', 'confirm-title');
    dlg.innerHTML = '<h2 class="h2" id="confirm-title">Bitte bestätigen</h2><p></p><div class="row-gap"><button type="button" class="btn btn-secondary" data-no>Abbrechen</button><button type="button" class="btn btn-primary" data-yes>Bestätigen</button></div>';
    document.body.appendChild(dlg);
    dlg.querySelector('[data-no]').addEventListener('click', function () { pending = null; dlg.close(); });
    dlg.querySelector('[data-yes]').addEventListener('click', function () {
      var el = pending; pending = null; dlg.close();
      if (!el) { return; }
      el.removeAttribute('data-confirm');
      if (el.form && el.type === 'submit') { el.form.requestSubmit(el); } else { el.click(); }
    });
    dlg.addEventListener('close', function () { pending = null; });
  }
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (!el) { return; }
    var msg = el.getAttribute('data-confirm');
    if (typeof HTMLDialogElement === 'undefined') {
      if (!window.confirm(msg)) { ev.preventDefault(); }
      return;
    }
    ev.preventDefault();
    if (!dlg) { buildDialog(); }
    pending = el;
    dlg.querySelector('p').textContent = msg;
    dlg.showModal();
    dlg.querySelector('[data-yes]').focus();
  });

  // Doppelklick-Schutz und Ladezustand: Formular nur einmal absenden
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      if (form.dataset.submitted === '1') { ev.preventDefault(); return; }
      form.dataset.submitted = '1';
      var btn = ev.submitter || form.querySelector('button[type=submit]');
      if (btn && btn.classList) { btn.classList.add('is-loading'); }
      window.setTimeout(function () {
        form.dataset.submitted = '';
        if (btn && btn.classList) { btn.classList.remove('is-loading'); }
      }, 8000);
    });
  });

  // Benutzermenü bei Klick ausserhalb schliessen
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.usermenu[open]').forEach(function (d) {
      if (!d.contains(ev.target)) { d.removeAttribute('open'); }
    });
  });
})();
