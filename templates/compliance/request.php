<?php $v->title = 'Anfrage'; $id = (int) $req['id']; ?>
<section class="stack">
  <p class="muted small"><a href="/compliance#anfragen">Compliance</a></p>
  <h1 class="h1">Anfrage #<?= $id ?> – <?= $req['request_type'] === 'access' ? 'Auskunft' : 'Löschung / Anonymisierung' ?></h1>
  <div class="card"><dl class="dl"><dt>Betroffen</dt><dd><?= e($req['subject_description']) ?></dd><dt>Status</dt><dd><?= e($req['status']) ?></dd><dt>Erfasst</dt><dd><?= e(fdt($req['created_at'])) ?></dd><dt>Entscheid</dt><dd><?= e(fdt($req['decided_at'])) ?></dd></dl></div>
  <?php if (!empty($note)): ?><div class="alert alert-warning" role="alert"><?= e($note) ?></div><?php endif; ?>
  <?php if ($report !== null): ?>
    <div class="card"><h2 class="h3">Auskunftsbericht (maschinell zusammengestellt)</h2>
      <p class="muted small">Prüfen Sie den Bericht fachlich, bevor Sie ihn weitergeben. Er enthält nur Daten, auf die Ihr Konto zugreifen darf.</p>
      <pre class="pre"><?= e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
  <?php endif; ?>
  <?php if ($req['request_type'] === 'erasure'): ?>
    <div class="card"><h2 class="h3">Hinderungsgründe für eine Löschung</h2>
      <?php if ($blockers === []): ?><p>Es wurden keine automatisch erkennbaren Hinderungsgründe gefunden. Die rechtliche Zulässigkeit ist trotzdem separat zu prüfen.</p>
      <?php else: ?><ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($req['status'] === 'open'): ?>
    <div class="card"><h2 class="h3">Entscheid festhalten</h2>
      <form method="post" action="/compliance/anfragen/<?= $id ?>/entscheid" class="stack"><?= $ui->csrf() ?>
        <?= $ui->field('status', 'Ergebnis', ['type' => 'select', 'options' => ['granted' => 'Entsprochen', 'partially_granted' => 'Teilweise entsprochen', 'refused' => 'Abgelehnt']]) ?>
        <?= $ui->field('reason', 'Begründung (bei Teil-/Ablehnung z. B. Aufbewahrungspflicht)', ['type' => 'textarea', 'rows' => 3, 'required' => true]) ?>
        <div><button class="btn btn-primary" type="submit" data-confirm="Entscheid verbindlich festhalten?">Entscheid speichern</button></div></form></div>
  <?php endif; ?>
</section>
