<?php $v->title = 'Einstellungen'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Einstellungen</h1>
  <form method="post" action="/verwaltung/einstellungen" class="card stack"><?= $ui->csrf() ?>
    <div class="grid-2">
      <?= $ui->field('name', 'Name des Mandanten', ['required' => true, 'value' => $settings['name']]) ?>
      <?= $ui->field('timezone', 'Zeitzone', ['required' => true, 'value' => $settings['timezone']]) ?>
      <?= $ui->field('jurisdiction_default', 'Standard-Rechtsgebiet', ['required' => true, 'value' => $settings['jurisdiction_default'], 'maxlength' => 6]) ?>
      <?= $ui->field('contract_number_prefix', 'Präfix der Vertragsnummern', ['required' => true, 'value' => $settings['contract_number_prefix'], 'maxlength' => 8]) ?>
    </div>
    <?= $ui->field('mfa_required', 'Mehrfaktor-Authentifizierung für alle Benutzer dieses Mandanten verlangen', ['type' => 'checkbox', 'value' => (string) (int) $settings['mfa_required']]) ?>
    <div><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <div class="grid-2">
    <div class="card"><h2 class="h3">Vertragsarten</h2><ul class="list-plain"><?php foreach ($types as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
      <?= $ui->form('/verwaltung/vertragsarten', '<div class="grid-2">' . $ui->field('code', 'Code', ['required' => true, 'maxlength' => 40, 'pattern' => '[a-z0-9_]+']) . $ui->field('name', 'Bezeichnung', ['required' => true, 'maxlength' => 120]) . '</div><button class="btn btn-secondary" type="submit">Hinzufügen</button>') ?></div>
    <div class="card"><h2 class="h3">Zusatzfelder für Verträge</h2><ul class="list-plain"><?php foreach ($fields as $f): ?><li><?= e($f['label']) ?> <span class="muted small">(<?= e($f['code']) ?>, <?= e($f['field_type']) ?><?= (int) $f['is_required'] ? ', Pflicht' : '' ?>)</span></li><?php endforeach; ?></ul>
      <?= $ui->form('/verwaltung/felder', '<div class="grid-2">' . $ui->field('code', 'Code', ['required' => true, 'maxlength' => 40, 'pattern' => '[a-z0-9_]+']) . $ui->field('label', 'Beschriftung', ['required' => true, 'maxlength' => 120]) . '</div>'
        . '<div class="grid-2">' . $ui->field('field_type', 'Typ', ['type' => 'select', 'options' => ['text' => 'Text', 'number' => 'Zahl', 'date' => 'Datum', 'boolean' => 'Ja/Nein', 'select' => 'Auswahl']]) . $ui->field('options', 'Optionen (Komma, nur Auswahl)') . '</div>'
        . $ui->field('required', 'Pflichtfeld', ['type' => 'checkbox']) . '<button class="btn btn-secondary" type="submit">Hinzufügen</button>') ?></div>
  </div>
</section>
