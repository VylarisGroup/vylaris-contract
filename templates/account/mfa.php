<?php $v->title = 'Zweiter Faktor'; ?>
<section class="stack narrow">
  <h1 class="h1">Zweiter Faktor (TOTP)</h1>
  <?= $v->partial('partials/account_nav', ['current' => '/konto/mfa']) ?>
  <p class="muted">Freiwillig. Alternativ oder zusätzlich können Sie sich <a href="/konto/passkeys">mit einem Passkey</a> anmelden.</p>
  <?php if ($enrollRequired): ?><div class="alert alert-warning" role="alert">Für Ihr Konto ist ein zweiter Faktor vorgeschrieben. Bis zur Einrichtung ist der Zugriff auf diese Seite beschränkt.</div><?php endif; ?>
  <?php if (is_array($recovery) && $recovery !== []): ?>
    <div class="card">
      <h2 class="h3">Wiederherstellungscodes</h2>
      <p class="alert alert-warning">Diese Codes werden <strong>nur jetzt</strong> angezeigt. Bewahren Sie sie getrennt von Ihrem Passwort auf. Jeder Code gilt einmal.</p>
      <ul class="codes"><?php foreach ($recovery as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($enabled): ?>
    <div class="card">
      <p><span class="badge badge-ok">aktiv</span> Ihr Konto ist mit einem zweiten Faktor geschützt.</p>
      <?= $ui->form('/konto/mfa/deaktivieren', $ui->field('password', 'Passwort zur Bestätigung', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
        . '<button class="btn btn-danger" type="submit" data-confirm="Zweiten Faktor wirklich deaktivieren?">Deaktivieren</button>') ?>
    </div>
  <?php elseif ($setup !== null): ?>
    <div class="card">
      <h2 class="h3">Schritt 2: Code bestätigen</h2>
      <p>Fügen Sie in Ihrer Authenticator-App (z. B. Aegis, 2FAS, Google/Microsoft Authenticator) einen Eintrag hinzu. Geben Sie den folgenden Schlüssel manuell ein:</p>
      <p><code class="secret"><?= e(implode(' ', str_split((string) $setup['secret'], 4))) ?></code></p>
      <p class="muted small">Typ: zeitbasiert (TOTP), 6 Stellen, 30 Sekunden, SHA-1. Mobile Geräte können auch diese Adresse öffnen: <a href="<?= e($setup['uri']) ?>">otpauth-Link</a>. (Ein QR-Code wird bewusst nicht erzeugt, damit der Schlüssel nie an Dritte übermittelt wird.)</p>
      <?= $ui->form('/konto/mfa/bestaetigen', $ui->field('code', '6-stelliger Code', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 6, 'pattern' => '[0-9]{6}'])
        . '<button class="btn btn-primary" type="submit">Aktivieren</button>') ?>
    </div>
  <?php else: ?>
    <div class="card">
      <h2 class="h3">Schritt 1: Einrichtung starten</h2>
      <p>Mit einem zweiten Faktor ist Ihr Konto auch bei Passwortverlust geschützt.</p>
      <?= $ui->action('/konto/mfa/start', 'Einrichtung starten', 'bx-shield-alt-2', 'btn btn-primary') ?>
    </div>
  <?php endif; ?>
</section>
