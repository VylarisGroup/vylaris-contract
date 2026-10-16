<?php $v->title = 'Mein Konto'; ?>
<section class="stack">
  <h1 class="h1">Mein Konto</h1>
  <div class="grid-2">
    <div class="card">
      <h2 class="h3">Profil</h2>
      <dl class="dl">
        <dt>Name</dt><dd><?= e($profile['display_name']) ?></dd>
        <dt>E-Mail</dt><dd><?= e($profile['email']) ?></dd>
        <dt>Letzte Anmeldung</dt><dd><?= e(fdt($profile['last_login_at'])) ?></dd>
        <dt>Zweiter Faktor</dt><dd><?= $profile['mfa_enabled_at'] !== null ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge badge-neutral">nicht aktiv</span>' ?> <a href="/konto/mfa">verwalten</a></dd>
        <dt>Passkeys</dt><dd><?= (int) $passkeyCount > 0 ? '<span class="badge badge-ok">' . (int) $passkeyCount . ' eingerichtet</span>' : '<span class="badge badge-neutral">keine</span>' ?> <a href="/konto/passkeys">verwalten</a></dd>
      </dl>
    </div>
    <div class="card">
      <h2 class="h3">Passwort ändern</h2>
      <?= $ui->form('/konto/passwort',
        $ui->field('current_password', 'Aktuelles Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
        . $ui->field('new_password', 'Neues Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'Mindestens 12 Zeichen. Andere Sitzungen werden beendet.'])
        . $ui->field('new_password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
        . '<button class="btn btn-primary" type="submit">Passwort ändern</button>') ?>
    </div>
  </div>
  <div class="card">
    <h2 class="h3">Sitzungen</h2>
    <p class="muted">Beendet alle Anmeldungen auf allen Geräten, auch diese.</p>
    <?= $ui->action('/konto/alle-abmelden', 'Überall abmelden', 'bx-log-out', 'btn btn-secondary', 'Wirklich auf allen Geräten abmelden?') ?>
  </div>
</section>
