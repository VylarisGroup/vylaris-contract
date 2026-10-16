<?php $v->title = 'Mandant'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Mandant</h1>
  <p class="muted">Stammdaten und Standardwerte Ihres Mandanten.</p>
  <form method="post" action="/verwaltung/einstellungen" class="card stack"><?= $ui->csrf() ?>
    <div class="grid-2">
      <?= $ui->field('name', 'Name des Mandanten', ['required' => true, 'value' => $settings['name']]) ?>
      <?= $ui->field('timezone', 'Zeitzone', ['required' => true, 'value' => $settings['timezone']]) ?>
      <?= $ui->field('jurisdiction_default', 'Standard-Rechtsgebiet', ['required' => true, 'value' => $settings['jurisdiction_default'], 'maxlength' => 6]) ?>
      <?= $ui->field('contract_number_prefix', 'Präfix der Vertragsnummern', ['required' => true, 'value' => $settings['contract_number_prefix'], 'maxlength' => 8]) ?>
    </div>
    <div><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>
