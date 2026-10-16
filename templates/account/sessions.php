<?php $v->title = 'Sitzungen'; ?>
<section class="stack narrow">
  <h1 class="h1">Sitzungen</h1>
  <?= $v->partial('partials/account_nav', ['current' => '/konto/sitzungen']) ?>
  <div class="card">
    <h2 class="h3">Sitzungen</h2>
    <p class="muted">Beendet alle Anmeldungen auf allen Geräten, auch diese.</p>
    <?= $ui->action('/konto/alle-abmelden', 'Überall abmelden', 'bx-log-out', 'btn btn-secondary', 'Wirklich auf allen Geräten abmelden?') ?>
  </div>
</section>
