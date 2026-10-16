<?php $v->title = 'Vertragsarten'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Vertragsarten</h1>
  <p class="muted">Arten von Verträgen, die bei der Erfassung zur Auswahl stehen.</p>
  <div class="card"><h2 class="h3">Vorhandene Arten</h2><ul class="list-plain"><?php foreach ($types as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
      <?= $ui->form('/verwaltung/vertragsarten',
        '<div class="wz-step stack" data-title="Bezeichnung" data-intro="Wie soll die Vertragsart in der Auswahl heissen?">' . $ui->field('name', 'Bezeichnung', ['required' => true, 'maxlength' => 120]) . '</div>'
        . '<div class="wz-step stack" data-title="Kürzel" data-intro="Ein kurzer technischer Code (Kleinbuchstaben, Ziffern, Unterstrich), z. B. «werkvertrag». Er kann später nicht geändert werden.">' . $ui->field('code', 'Code', ['required' => true, 'maxlength' => 40, 'pattern' => '[a-z0-9_]+']) . '</div>'
        . '<div class="wz-actions"><button class="btn btn-secondary" type="submit">Hinzufügen</button></div>', false, '', 'wizard-on') ?></div>
</section>
