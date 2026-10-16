/* Assistenten für Formulare (Progressive Enhancement).
   Ein Formular mit data-wizard enthält Schritte (.wz-step, data-title, data-intro) und einen Aktionsbereich (.wz-actions).
   Ohne JavaScript erscheinen alle Felder wie gewohnt; mit JavaScript lässt sich zwischen «Schritt für Schritt» und
   «Alle Felder» umschalten. Gesendet wird in beiden Fällen dasselbe Formular, geprüft wird ausschliesslich serverseitig. */
(function () {
  'use strict';

  // Seitenleiste: Gruppen merken (die Gruppe der aktuellen Seite bleibt offen)
  var KEY = 'vp-navgroups';
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }
  document.querySelectorAll('details.navgroup').forEach(function (d) {
    var k = d.getAttribute('data-navgroup');
    if (d.getAttribute('data-active') !== '1' && Object.prototype.hasOwnProperty.call(saved, k)) { d.open = !!saved[k]; }
    d.addEventListener('toggle', function () {
      saved[k] = d.open;
      try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* ohne Speicher weiter */ }
    });
  });

  var MODE = 'vp-wizard-mode';
  function getMode(def) {
    try { var m = localStorage.getItem(MODE); if (m === 'wizard' || m === 'all') { return m; } } catch (e) { /* ignorieren */ }
    return def;
  }
  function setMode(m) { try { localStorage.setItem(MODE, m); } catch (e) { /* ignorieren */ } }

  function labelOf(el, form) {
    var id = el.getAttribute('id');
    var l = id ? form.querySelector('label[for="' + id.replace(/"/g, '') + '"]') : null;
    if (!l) { l = el.closest('label'); }
    return l ? l.textContent.replace(/\*/g, '').replace(/\s+/g, ' ').trim() : (el.getAttribute('name') || '');
  }
  function valueOf(el) {
    if (el.type === 'checkbox') { return el.checked ? 'Ja' : ''; }
    if (el.type === 'file') { return el.files && el.files.length ? el.files[0].name : ''; }
    if (el.tagName === 'SELECT') {
      var out = [];
      for (var i = 0; i < el.options.length; i++) { if (el.options[i].selected && el.options[i].value !== '') { out.push(el.options[i].textContent.trim()); } }
      return out.join(', ');
    }
    return (el.value || '').trim();
  }

  document.querySelectorAll('form[data-wizard]').forEach(function (form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll(':scope > .wz-step'));
    var actions = form.querySelector(':scope > .wz-actions');
    if (steps.length < 2 || !actions) { return; }
    var def = form.getAttribute('data-wizard') === 'auto' ? 'wizard' : 'all';
    var mode = getMode(def);
    var cur = 0;

    // Zusammenfassung als letzter Schritt
    var summary = document.createElement('div');
    summary.className = 'wz-step wz-summary';
    summary.setAttribute('data-title', 'Prüfen und speichern');
    summary.setAttribute('data-intro', 'Bitte kontrollieren Sie Ihre Angaben. Mit «Zurück» können Sie jederzeit korrigieren.');
    form.insertBefore(summary, actions);
    var all = steps.concat([summary]);

    // Kopf: Schrittanzeige, Fortschritt, Umschalter
    var bar = document.createElement('div'); bar.className = 'wz-bar';
    var top = document.createElement('div'); top.className = 'wz-top';
    var ol = document.createElement('ol'); ol.className = 'wz-steps';
    var pills = all.map(function (st, i) {
      var li = document.createElement('li'); var b = document.createElement('button');
      b.type = 'button'; b.className = 'wz-pill';
      var n = document.createElement('b'); n.textContent = String(i + 1); n.setAttribute('aria-hidden', 'true');
      var t = document.createElement('span'); t.textContent = st.getAttribute('data-title') || ('Schritt ' + (i + 1));
      b.appendChild(n); b.appendChild(t);
      b.addEventListener('click', function () { go(i); });
      li.appendChild(b); ol.appendChild(li); return b;
    });
    var toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'btn btn-ghost btn-sm';
    toggle.addEventListener('click', function () { apply(mode === 'wizard' ? 'all' : 'wizard'); });
    top.appendChild(ol); top.appendChild(toggle);
    var prog = document.createElement('div'); prog.className = 'wz-progress'; prog.setAttribute('aria-hidden', 'true');
    var fill = document.createElement('div'); prog.appendChild(fill);
    var live = document.createElement('p'); live.className = 'sr-only'; live.setAttribute('aria-live', 'polite');
    bar.appendChild(top); bar.appendChild(prog); bar.appendChild(live);
    form.insertBefore(bar, steps[0]);

    // Überschriften je Schritt
    all.forEach(function (st) {
      var h = document.createElement('div'); h.className = 'wz-head';
      var h2 = document.createElement('h2'); h2.textContent = st.getAttribute('data-title') || ''; h2.tabIndex = -1;
      h.appendChild(h2);
      if (st.getAttribute('data-intro')) { var p = document.createElement('p'); p.textContent = st.getAttribute('data-intro'); h.appendChild(p); }
      st.insertBefore(h, st.firstChild);
    });

    // Navigation
    var nav = document.createElement('div'); nav.className = 'wz-nav';
    var back = document.createElement('button'); back.type = 'button'; back.className = 'btn btn-secondary';
    back.innerHTML = '<i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Zurück';
    var next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-primary';
    next.innerHTML = 'Weiter <i class="bx bx-right-arrow-alt" aria-hidden="true"></i>';
    nav.appendChild(back); nav.appendChild(next);
    form.insertBefore(nav, actions);
    back.addEventListener('click', function () { go(cur - 1); });
    next.addEventListener('click', function () { go(cur + 1); });

    function controls(st) { return Array.prototype.slice.call(st.querySelectorAll('input,select,textarea')); }
    function validOf(i) {
      var c = controls(all[i]);
      for (var k = 0; k < c.length; k++) { if (!c[k].checkValidity()) { c[k].reportValidity(); return false; } }
      return true;
    }
    function buildSummary() {
      summary.querySelectorAll('.wz-sum').forEach(function (n) { n.remove(); });
      var wrap = document.createElement('div'); wrap.className = 'wz-sum';
      var any = false;
      steps.forEach(function (st) {
        var dl = document.createElement('dl'); var n = 0; var seen = {};
        controls(st).forEach(function (el) {
          if (el.type === 'hidden' || el.type === 'password' || el.disabled) { return; }
          var v = valueOf(el); if (v === '') { return; }
          var lab = labelOf(el, form); var key = lab + '|' + v; if (seen[key]) { return; } seen[key] = 1;
          var dt = document.createElement('dt'); dt.textContent = lab; var dd = document.createElement('dd'); dd.textContent = v;
          dl.appendChild(dt); dl.appendChild(dd); n++;
        });
        if (n > 0) {
          any = true;
          var h3 = document.createElement('h3'); h3.textContent = st.getAttribute('data-title') || '';
          wrap.appendChild(h3); wrap.appendChild(dl);
        }
      });
      if (!any) { var e = document.createElement('p'); e.className = 'muted'; e.textContent = 'Es wurden noch keine Angaben gemacht.'; wrap.appendChild(e); }
      summary.appendChild(wrap);
    }
    function render(focus) {
      var wiz = mode === 'wizard';
      form.classList.toggle('wz-on', wiz); form.classList.toggle('wz-off', !wiz);
      all.forEach(function (st, i) { st.hidden = wiz && i !== cur; });
      var last = cur === all.length - 1;
      actions.hidden = wiz && !last;
      back.hidden = cur === 0; next.hidden = last;
      pills.forEach(function (b, i) {
        b.classList.toggle('is-current', i === cur); b.classList.toggle('is-done', i < cur);
        if (i === cur) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
      });
      fill.style.width = Math.round(((cur + 1) / all.length) * 100) + '%';
      toggle.innerHTML = wiz ? '<i class="bx bx-list-ul" aria-hidden="true"></i> Alle Felder anzeigen' : '<i class="bx bx-list-check" aria-hidden="true"></i> Schritt für Schritt';
      if (wiz) {
        if (last) { buildSummary(); }
        live.textContent = 'Schritt ' + (cur + 1) + ' von ' + all.length + ': ' + (all[cur].getAttribute('data-title') || '');
        if (focus) { var h = all[cur].querySelector('.wz-head h2'); if (h) { h.focus({ preventScroll: false }); } }
      }
    }
    function go(i) {
      if (i < 0 || i >= all.length) { return; }
      if (i > cur) { for (var k = cur; k < i; k++) { if (k < steps.length && !validOf(k)) { cur = k; render(false); return; } } }
      cur = i; render(true);
    }
    function apply(m) { mode = m; setMode(m); render(m === 'wizard'); }

    form.addEventListener('keydown', function (ev) {
      if (mode !== 'wizard' || ev.key !== 'Enter') { return; }
      var t = ev.target;
      if (t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || t.tagName === 'SELECT' || (t.tagName === 'INPUT' && (t.type === 'submit' || t.type === 'checkbox'))) { return; }
      ev.preventDefault();
      if (cur < all.length - 1) { go(cur + 1); }
    });
    form.addEventListener('submit', function (ev) {
      // Im Assistenten nur auf der letzten Seite absenden; sonst fehlerhaften Schritt anzeigen
      if (mode === 'wizard') {
        for (var k = 0; k < steps.length; k++) { if (!validOf(k)) { ev.preventDefault(); cur = k; render(true); return; } }
      }
    });

    // Serverfehler: zum ersten fehlerhaften Schritt springen
    for (var i = 0; i < steps.length; i++) {
      if (steps[i].querySelector('[aria-invalid="true"]')) { cur = i; break; }
    }
    render(false);
  });
})();
