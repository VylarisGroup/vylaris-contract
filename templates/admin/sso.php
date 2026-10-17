<?php $v->title = 'Single Sign-on (SSO)';
$on = $c !== null && (int) $c['enabled'] === 1; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Single Sign-on (OpenID Connect)</h1>
  <p class="muted">Ihre Mitarbeitenden melden sich mit dem Identitätsanbieter Ihrer Organisation an (z. B. Entra ID, Google Workspace, Keycloak, VylarisAuth). Passwort- und Passkey-Anmeldung bleiben bestehen.</p>

  <div class="card stack">
    <h2 class="h3">Daten für die Einrichtung beim Identitätsanbieter</h2>
    <dl class="kv">
      <dt>Redirect-URI</dt><dd><code><?= e($redirectUri) ?></code></dd>
      <dt>Kürzel für die Anmeldung</dt><dd><code><?= e($slug) ?></code> <span class="muted small">(Direktlink: <code>/anmelden/sso/<?= e($slug) ?></code>)</span></dd>
      <dt>Verfahren</dt><dd>Authorization Code mit PKCE (S256), ID-Token mit RS256-Signatur. Scopes: openid email profile.</dd>
    </dl>
    <p class="small muted">Status: <?= $on ? '<strong>aktiv</strong>' : ($c !== null ? 'eingerichtet, nicht aktiv' : 'nicht eingerichtet') ?></p>
  </div>

  <div class="card stack">
    <h2 class="h3">Einstellungen</h2>
    <?= $ui->form('/verwaltung/sso',
      '<div class="wz-step stack" data-title="Identitätsanbieter" data-intro="Die Adresse (Issuer) finden Sie in der Dokumentation Ihres Anbieters; sie endet meist ohne Schrägstrich. Wir rufen dort /.well-known/openid-configuration ab.">'
        . $ui->field('issuer', 'Issuer-Adresse', ['required' => true, 'type' => 'url', 'value' => $c['issuer'] ?? '', 'placeholder' => 'https://login.example.com/realms/firma', 'maxlength' => 255])
        . '</div>'
      . '<div class="wz-step stack" data-title="Zugangsdaten" data-intro="Client-ID und -Geheimnis legen Sie beim Anbieter an (Anwendungstyp «Web», Redirect-URI wie oben). Das Geheimnis wird verschlüsselt gespeichert; leer lassen = unverändert.">'
        . $ui->field('client_id', 'Client-ID', ['required' => true, 'value' => $c['client_id'] ?? '', 'maxlength' => 255, 'autocomplete' => 'off'])
        . $ui->field('client_secret', 'Client-Geheimnis', ['type' => 'password', 'autocomplete' => 'new-password', 'help' => ($c['has_secret'] ?? 0) ? 'Ein Geheimnis ist gespeichert.' : 'Optional, wenn der Anbieter PKCE ohne Geheimnis erlaubt.'])
        . '</div>'
      . '<div class="wz-step stack" data-title="Zugang" data-intro="Konten werden nur über eine vom Anbieter bestätigte E-Mail-Adresse mit bestehenden Mitgliedern verknüpft. Neue Konten entstehen nur, wenn Sie das hier ausdrücklich erlauben.">'
        . $ui->field('allowed_domains', 'Erlaubte E-Mail-Domains', ['value' => str_replace(',', ', ', (string) ($c['allowed_domains'] ?? '')), 'placeholder' => 'firma.ch, firma.com', 'help' => 'Komma-getrennt. Leer = jede Domain (nur für bestehende Mitglieder). Mit Domain-Angabe findet «Mit SSO anmelden» Ihren Mandanten auch über die E-Mail-Adresse.'])
        . '<label class="check"><input type="checkbox" name="auto_provision" value="1"' . (($c['auto_provision'] ?? 0) ? ' checked' : '') . '> <span>Unbekannte Personen dieser Domains beim ersten SSO-Login automatisch als Benutzer anlegen</span></label>'
        . $ui->field('default_role', 'Rolle für automatisch angelegte Benutzer', ['type' => 'select', 'value' => $c['default_role'] ?? 'reader', 'options' => ['reader' => 'Leser', 'approver' => 'Freigeber', 'contract_manager' => 'Vertragsverantwortliche']])
        . '</div>'
      . '<div class="wz-step stack" data-title="Aktivieren" data-intro="Beim Speichern mit aktiviertem SSO prüfen wir, ob der Anbieter erreichbar und korrekt konfiguriert ist.">'
        . '<label class="check"><input type="checkbox" name="enabled" value="1"' . ($on ? ' checked' : '') . '> <span>SSO für diesen Mandanten aktivieren</span></label>'
        . '</div>'
      . '<div class="wz-actions"><button class="btn btn-primary" type="submit"><i class="bx bx-save" aria-hidden="true"></i> Speichern</button></div>', false, 'stack', 'wizard-on') ?>
    <?php if ($c !== null): ?><div><?= $ui->action('/verwaltung/sso/entfernen', 'SSO-Konfiguration entfernen', 'bx-trash', 'btn btn-ghost btn-sm', 'SSO entfernen? Alle Verknüpfungen werden gelöscht; die Anmeldung mit Passwort bleibt.') ?></div><?php endif; ?>
  </div>

  <div class="card stack">
    <h2 class="h3">Verknüpfte Identitäten</h2>
    <?php if ($identities === []): ?><p class="muted">Noch niemand hat sich per SSO angemeldet.</p><?php else: ?>
      <ul class="list-plain"><?php foreach ($identities as $i): ?>
        <li class="row-between"><span><strong><?= e($i['display_name']) ?></strong> <span class="muted small"><?= e($i['email']) ?> · zuletzt <?= e($i['last_login_at'] ? fdt($i['last_login_at']) : '–') ?></span></span>
          <?= $ui->action('/verwaltung/sso/verknuepfung/' . (int) $i['id'] . '/loesen', 'Lösen', 'bx-unlink', 'btn btn-ghost btn-sm', 'Verknüpfung lösen? Die Person kann sich weiterhin mit Passwort anmelden; beim nächsten SSO-Login wird neu verknüpft.') ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="h3">Gut zu wissen</h2>
    <ul class="list-plain small">
      <li>Eine SSO-Sitzung gilt nur für diesen Mandanten. Systemadministratoren und externe Konten können sich nicht per SSO anmelden.</li>
      <li>Der Zugriff folgt Ihrem Anbieter: wer dort gesperrt wird, kann sich nicht mehr per SSO anmelden; bestehende Sitzungen und Passwort-Logins sperren Sie unter «Benutzer».</li>
      <li>Unterstützt wird RS256 als Signaturverfahren des ID-Tokens. Getestet wurde gegen einen simulierten Anbieter, nicht gegen jeden Hersteller – bitte zuerst mit einem Testkonto prüfen.</li>
    </ul>
  </div>
</section>
