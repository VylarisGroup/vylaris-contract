<?php $v->title = 'Mein Konto'; ?>
<section class="stack narrow">
  <h1 class="h1">Mein Konto</h1>
  <?= $v->partial('partials/account_nav', ['current' => '/konto']) ?>
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
</section>
