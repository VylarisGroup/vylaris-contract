<?php $v->title = 'Passkeys'; ?>
<section class="stack narrow">
  <h1 class="h1">Passkeys</h1>
  <div class="card">
    <p>Mit einem Passkey melden Sie sich ohne Passwort an – per Fingerabdruck, Gesichtserkennung, Geräte-PIN oder Sicherheitsschlüssel. Er ersetzt Passwort <em>und</em> zweiten Faktor, ist an diese Website gebunden und lässt sich nicht abfangen. Passkeys sind freiwillig; die Anmeldung mit Passwort bleibt möglich.</p>
    <?php if (!$available): ?><div class="alert alert-warning" role="status">Passkeys sind auf diesem System nicht verfügbar: Die öffentliche Adresse (APP_URL) muss eine https-Adresse mit Domainnamen sein (System → Einstellungen).</div><?php endif; ?>
  </div>
  <div class="card">
    <h2 class="h2">Ihre Passkeys</h2>
    <?php if ($items === []): ?><p class="muted">Noch kein Passkey eingerichtet.</p>
    <?php else: ?><ul class="list-plain"><?php foreach ($items as $k): ?>
      <li class="row-between"><span><i class="bx bx-fingerprint" aria-hidden="true"></i> <strong><?= e($k['name']) ?></strong>
        <span class="muted small"> · hinzugefügt <?= e(fdt($k['created_at'])) ?> · zuletzt benutzt <?= $k['last_used_at'] ? e(fdt($k['last_used_at'])) : 'noch nie' ?><?= (int) $k['backup_eligible'] === 1 ? ' · synchronisiert' : '' ?></span></span>
        <?= $ui->action('/konto/passkeys/' . (int) $k['id'] . '/loeschen', 'Entfernen', 'bx-trash', 'btn btn-ghost btn-sm', 'Diesen Passkey entfernen? Sie können sich damit nicht mehr anmelden.') ?></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php if ($available): ?>
  <div class="card" data-passkey-register data-options-url="/konto/passkeys/optionen" data-register-url="/konto/passkeys/registrieren" data-csrf="<?= e($csrf) ?>" hidden>
    <h2 class="h2">Passkey hinzufügen</h2>
    <div class="stack">
      <?= $ui->field('pk_name', 'Bezeichnung', ['maxlength' => 100, 'placeholder' => 'z. B. Laptop oder Telefon', 'help' => 'Hilft Ihnen später, die Passkeys zu unterscheiden.']) ?>
      <?= $ui->field('pk_password', 'Passwort zur Bestätigung', ['type' => 'password', 'autocomplete' => 'current-password']) ?>
      <p class="field-error" role="alert" data-passkey-error hidden></p>
      <div><button class="btn btn-primary" type="button" data-passkey-button><i class="bx bx-fingerprint" aria-hidden="true"></i> Passkey einrichten</button></div>
    </div>
  </div>
  <p class="muted small" data-passkey-unsupported hidden>Dieser Browser unterstützt keine Passkeys.</p>
  <?php endif; ?>
  <p><a href="/konto">← Zurück zum Konto</a></p>
</section>
