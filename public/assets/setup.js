/* Installationsassistent: zeigt die Schritte nacheinander. Ohne JavaScript bleiben alle Schritte sichtbar;
   geprüft wird immer serverseitig. Es werden keine Eingaben zwischengespeichert. */
(function () {
  'use strict';
  var form = document.getElementById('wizard');
  if (!form) { return; }
  var steps = Array.prototype.slice.call(form.querySelectorAll('.wizard-step'));
  var dots = Array.prototype.slice.call(form.querySelectorAll('[data-dot]'));
  var nav = form.querySelector('[data-nav]');
  var prev = form.querySelector('[data-prev]');
  var next = form.querySelector('[data-next]');
  var testBtn = form.querySelector('[data-db-test]');
  var result = form.querySelector('[data-db-result]');
  var cur = 0;
  form.classList.add('is-js');
  nav.hidden = false;
  if (testBtn) { testBtn.hidden = false; }

  function val(name) { var el = form.elements[name]; return el ? String(el.value).trim() : ''; }

  function show(i, focus) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, k) { s.hidden = k !== cur; });
    dots.forEach(function (d, k) {
      d.classList.toggle('is-current', k === cur);
      d.classList.toggle('is-done', k < cur);
      if (k === cur) { d.setAttribute('aria-current', 'step'); } else { d.removeAttribute('aria-current'); }
    });
    prev.hidden = cur === 0;
    next.hidden = cur === steps.length - 1;
    var skip = cur === 4 && !val('smtp_host');
    next.firstChild.textContent = skip ? 'Überspringen ' : 'Weiter ';
    if (cur === steps.length - 1) { summary(); }
    if (focus !== false) {
      var f = steps[cur].querySelector('input:not([type=hidden]),select');
      window.scrollTo({ top: 0, behavior: 'smooth' });
      if (f) { f.focus({ preventScroll: true }); }
    }
  }

  function stepValid(i) {
    var ok = true, first = null;
    steps[i].querySelectorAll('input,select').forEach(function (el) {
      if (!el.checkValidity()) { ok = false; first = first || el; el.setAttribute('aria-invalid', 'true'); } else { el.removeAttribute('aria-invalid'); }
    });
    if (i === 3 && ok && val('admin_password') !== val('admin_password2')) {
      ok = false; first = form.elements.admin_password2; first.setAttribute('aria-invalid', 'true');
      first.setCustomValidity('Die Passwörter stimmen nicht überein.'); first.reportValidity(); first.setCustomValidity('');
      return false;
    }
    if (i === 3 && ok && val('admin_password').length < 12) {
      first = form.elements.admin_password; first.setAttribute('aria-invalid', 'true');
      first.setCustomValidity('Mindestens 12 Zeichen.'); first.reportValidity(); first.setCustomValidity('');
      return false;
    }
    if (!ok && first) { first.reportValidity(); first.focus(); }
    return ok;
  }

  function summary() {
    var dl = form.querySelector('[data-summary]');
    var rows = [
      ['Adresse', val('app_url')],
      ['Datenbank', val('db_user') + '@' + val('db_host') + ':' + val('db_port') + ' / ' + val('db_name')],
      ['Administration', val('admin_name') + ' (' + val('admin_email') + ')'],
      ['E-Mail-Versand', val('smtp_host') ? val('smtp_host') + ' · Absender ' + val('mail_from') : 'nicht eingerichtet (später nachtragbar)'],
      ['Max. Dateigrösse', val('max_upload_mb') + ' MB']
    ];
    dl.textContent = '';
    rows.forEach(function (r) {
      var dt = document.createElement('dt'); dt.textContent = r[0];
      var dd = document.createElement('dd'); dd.textContent = r[1];
      dl.appendChild(dt); dl.appendChild(dd);
    });
  }

  next.addEventListener('click', function () { if (stepValid(cur)) { show(cur + 1); } });
  prev.addEventListener('click', function () { show(cur - 1); });
  dots.forEach(function (d, k) {
    d.addEventListener('click', function () {
      if (k <= cur) { show(k); return; }
      for (var i = cur; i < k; i++) { if (!stepValid(i)) { show(i); return; } }
      show(k);
    });
  });
  form.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && cur < steps.length - 1) { ev.preventDefault(); next.click(); }
  });
  form.addEventListener('submit', function (ev) {
    for (var i = 0; i < steps.length - 1; i++) {
      if (i === 4 && !val('smtp_host')) { continue; }
      if (!stepValid(i)) { ev.preventDefault(); show(i, false); return; }
    }
  });

  // Passwortfelder: anzeigen/verbergen
  form.querySelectorAll('input[type=password]').forEach(function (inp) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-ghost btn-sm pw-toggle'; b.textContent = 'Anzeigen';
    b.setAttribute('aria-label', 'Passwort anzeigen oder verbergen');
    b.addEventListener('click', function () {
      var hide = inp.type === 'password';
      inp.type = hide ? 'text' : 'password'; b.textContent = hide ? 'Verbergen' : 'Anzeigen';
    });
    inp.insertAdjacentElement('afterend', b);
  });

  // Datenbank-Verbindung testen
  if (testBtn) {
    testBtn.addEventListener('click', function () {
      var data = new FormData();
      ['_csrf', 'setup_key', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass'].forEach(function (n) { data.append(n, val(n)); });
      result.className = 'small muted'; result.textContent = 'Wird geprüft …'; testBtn.disabled = true;
      fetch('/setup/db-test', { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { result.className = 'small ' + (j.ok ? 'text-ok' : 'text-bad'); result.textContent = j.message; })
        .catch(function () { result.className = 'small text-bad'; result.textContent = 'Der Test konnte nicht ausgeführt werden.'; })
        .then(function () { testBtn.disabled = false; });
    });
  }

  show(parseInt(form.getAttribute('data-start') || '0', 10), false);
})();
