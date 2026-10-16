<?php $v->title = 'Start'; ?>
<section class="stack">
  <h1 class="h1">Willkommen, <?= e($p->displayName) ?></h1>
  <p class="muted">Sie sehen ausschliesslich Verträge, für die Ihnen ein zeitlich befristeter Zugriff eingeräumt wurde. Jeder Zugriff wird protokolliert.</p>
  <div class="stats">
    <a class="stat" href="/vertraege"><span class="stat-n"><?= (int) $data['total'] ?></span><span class="stat-l">Zugängliche Verträge</span></a>
    <?php if ($p->userType === 'notary'): ?><a class="stat" href="/portal/notariat"><span class="stat-n"><?= count($notary) ?></span><span class="stat-l">Notariatsfälle</span></a><?php endif; ?>
  </div>
  <?php if ($data['expiring'] !== []): ?>
  <div class="card"><h2 class="h3">Bald auslaufend</h2><ul class="list-plain"><?php foreach ($data['expiring'] as $c): ?>
    <li><a href="/vertraege/<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?> – <?= e($c['title']) ?></a> <span class="muted small">bis <?= e(fdate($c['end_date'])) ?></span></li>
  <?php endforeach; ?></ul></div>
  <?php endif; ?>
</section>
