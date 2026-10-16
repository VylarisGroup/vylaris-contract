<?php $v->title = 'Mandant wählen'; ?>
<section class="stack narrow">
  <h1 class="h1">Mandant wählen</h1>
  <?php if ($tenants === [] && !$isSys): ?><p>Ihr Konto ist keinem aktiven Mandanten zugeordnet.</p><?php endif; ?>
  <ul class="list-plain">
  <?php foreach ($tenants as $t): ?>
    <li><?= $ui->action('/mandant-waehlen', $t['name'], 'bx-buildings', 'btn btn-secondary w-full', null, ['tenant_id' => (int) $t['id']]) ?></li>
  <?php endforeach; ?>
  <?php if ($isSys): ?>
    <li><?= $ui->action('/mandant-waehlen', 'Systemverwaltung (ohne Mandant)', 'bx-server', 'btn btn-primary w-full', null, ['tenant_id' => 0]) ?></li>
  <?php endif; ?>
  </ul>
</section>
