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

  // Passwortfelder: Anzeigen/Verbergen (Schaltfläche nur mit JavaScript; der Assistent hat eine eigene Variante)
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    if (inp.closest('#wizard') || inp.autocomplete === 'one-time-code') { return; }
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-ghost btn-sm pw-toggle'; b.textContent = 'Anzeigen';
    b.setAttribute('aria-label', 'Passwort anzeigen oder verbergen');
    b.addEventListener('click', function () {
      var hide = inp.type === 'password';
      inp.type = hide ? 'text' : 'password'; b.textContent = hide ? 'Verbergen' : 'Anzeigen';
    });
    inp.insertAdjacentElement('afterend', b);
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

  // ---- Bewegung: Scroll-Einblendung, Fortschrittsbalken, Zählanimation ----
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var targets = Array.prototype.slice.call(document.querySelectorAll('.page .card, .page .stat, .page .tile, .page .table-wrap, .page .h1, .guest-card'))
    .filter(function (el) { return !el.parentElement.closest('.card, dialog'); });
  function countUp(el) {
    var txt = el.textContent.trim();
    if (reduce || !/^\d{1,6}$/.test(txt)) { return; }
    var to = parseInt(txt, 10), t0 = null;
    if (to === 0) { return; }
    function step(ts) {
      if (t0 === null) { t0 = ts; }
      var k = Math.min(1, (ts - t0) / 700), e = 1 - Math.pow(1 - k, 3);
      el.textContent = String(Math.round(to * e));
      if (k < 1) { window.requestAnimationFrame(step); }
    }
    el.textContent = '0';
    window.requestAnimationFrame(step);
  }
  function reveal(el, i) {
    el.style.setProperty('--i', String(Math.min(i, 6)));
    el.classList.add('is-in');
    el.querySelectorAll('.stat-n').forEach(countUp);
    if (el.classList.contains('stat-n')) { countUp(el); }
  }
  if (!reduce && 'IntersectionObserver' in window) {
    var batch = 0, timer = null;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { reveal(en.target, batch++); io.unobserve(en.target); }
      });
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { batch = 0; }, 120);
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    targets.forEach(function (el) { el.setAttribute('data-reveal', ''); io.observe(el); });
  }

  var bar = document.querySelector('.scroll-progress');
  var mobilebar = document.querySelector('.mobilebar');
  var ticking = false;
  function onScroll() {
    ticking = false;
    var h = document.documentElement.scrollHeight - window.innerHeight;
    var y = window.pageYOffset || document.documentElement.scrollTop;
    if (bar) {
      bar.classList.toggle('is-on', y > 24 && h > 80);
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, y / h) : 0).toFixed(4) + ')';
    }
    if (mobilebar) { mobilebar.classList.toggle('is-stuck', y > 4); }
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; window.requestAnimationFrame(onScroll); }
  }, { passive: true });
  onScroll();
})();

// SSO: Weiterleitungsseite klickt den Link selbst (Formular-Weiterleitungen nach aussen sind per CSP gesperrt)
(function () { var a = document.querySelector('[data-autoclick]'); if (a) { setTimeout(function () { a.click(); }, 300); } })();
