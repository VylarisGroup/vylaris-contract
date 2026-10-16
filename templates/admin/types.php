<?php $v->title = 'Vertragsarten'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Vertragsarten</h1>
  <p class="muted">Arten von Verträgen, die bei der Erfassung zur Auswahl stehen.</p>
  <div class="card"><h2 class="h3">Vorhandene Arten</h2><ul class="list-plain"><?php foreach ($types as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
      <?= $ui->form('/verwaltung/vertragsarten', '<div class="grid-2">' . $ui->field('code', 'Code', ['required' => true, 'maxlength' => 40, 'pattern' => '[a-z0-9_]+']) . $ui->field('name', 'Bezeichnung', ['required' => true, 'maxlength' => 120]) . '</div><button class="btn btn-secondary" type="submit">Hinzufügen</button>') ?></div>
</section>
