(function () {
  'use strict';
  var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

  function b64uToBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) { s += '='; }
    var bin = atob(s), buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { buf[i] = bin.charCodeAt(i); }
    return buf.buffer;
  }
  function bufToB64u(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function post(url, csrf, fields) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unerwartete Antwort des Servers.' }; }); });
  }
  function showError(root, msg) {
    var el = root.querySelector('[data-passkey-error]');
    if (!el) { return; }
    el.textContent = msg || '';
    el.hidden = !msg;
  }
  function busy(btn, on) { btn.disabled = on; btn.classList.toggle('is-loading', on); }
  function friendly(e) {
    if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) { return 'Abgebrochen oder Zeitüberschreitung. Bitte erneut versuchen.'; }
    if (e && e.name === 'InvalidStateError') { return 'Dieser Passkey ist bereits für Ihr Konto eingerichtet.'; }
    if (e && e.name === 'SecurityError') { return 'Passkeys funktionieren nur über die offizielle https-Adresse dieser Website.'; }
    return 'Passkey-Vorgang fehlgeschlagen.';
  }

  // ---- Anmeldung ----
  var login = document.querySelector('[data-passkey-login]');
  if (login && supported) {
    login.hidden = false;
    var lb = login.querySelector('[data-passkey-button]');
    lb.addEventListener('click', function () {
      showError(login, '');
      busy(lb, true);
      post(login.dataset.optionsUrl, login.dataset.csrf, {}).then(function (res) {
        if (!res.ok) { throw { server: res.error }; }
        var o = res.options;
        return navigator.credentials.get({ publicKey: {
          challenge: b64uToBuf(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification, allowCredentials: []
        } });
      }).then(function (cred) {
        var r = cred.response;
        var payload = { id: cred.id, rawId: bufToB64u(cred.rawId), type: cred.type, response: {
          authenticatorData: bufToB64u(r.authenticatorData), clientDataJSON: bufToB64u(r.clientDataJSON), signature: bufToB64u(r.signature),
          userHandle: r.userHandle ? bufToB64u(r.userHandle) : ''
        } };
        return post(login.dataset.loginUrl, login.dataset.csrf, { credential: JSON.stringify(payload), weiter: login.dataset.next || '/' });
      }).then(function (res) {
        if (!res.ok) { throw { server: res.error }; }
        window.location.assign(res.redirect || '/');
      }).catch(function (e) {
        busy(lb, false);
        showError(login, (e && e.server) || friendly(e));
      });
    });
  }

  // ---- Einrichtung ----
  var reg = document.querySelector('[data-passkey-register]');
  var unsup = document.querySelector('[data-passkey-unsupported]');
  if (reg && !supported && unsup) { unsup.hidden = false; }
  if (reg && supported) {
    reg.hidden = false;
    var rb = reg.querySelector('[data-passkey-button]');
    rb.addEventListener('click', function () {
      showError(reg, '');
      var pw = document.getElementById('f_pk_password').value;
      if (!pw) { showError(reg, 'Bitte geben Sie Ihr Passwort zur Bestätigung ein.'); return; }
      busy(rb, true);
      post(reg.dataset.optionsUrl, reg.dataset.csrf, { password: pw }).then(function (res) {
        if (!res.ok) { throw { server: res.error }; }
        var o = res.options;
        return navigator.credentials.create({ publicKey: {
          rp: o.rp, user: { id: b64uToBuf(o.user.id), name: o.user.name, displayName: o.user.displayName },
          challenge: b64uToBuf(o.challenge), pubKeyCredParams: o.pubKeyCredParams, timeout: o.timeout, attestation: o.attestation,
          authenticatorSelection: o.authenticatorSelection,
          excludeCredentials: o.excludeCredentials.map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; })
        } });
      }).then(function (cred) {
        var r = cred.response;
        var payload = { id: cred.id, rawId: bufToB64u(cred.rawId), type: cred.type, response: {
          clientDataJSON: bufToB64u(r.clientDataJSON), attestationObject: bufToB64u(r.attestationObject),
          transports: r.getTransports ? r.getTransports() : []
        } };
        return post(reg.dataset.registerUrl, reg.dataset.csrf, { credential: JSON.stringify(payload), name: document.getElementById('f_pk_name').value });
      }).then(function (res) {
        if (!res.ok) { throw { server: res.error }; }
        window.location.assign(res.redirect || '/konto/passkeys');
      }).catch(function (e) {
        busy(rb, false);
        showError(reg, (e && e.server) || friendly(e));
      });
    });
  }
})();
