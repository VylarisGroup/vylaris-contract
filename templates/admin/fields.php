<?php $v->title = 'Zusatzfelder'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Zusatzfelder</h1>
  <p class="muted">Eigene Felder, die bei Verträgen zusätzlich erfasst werden.</p>
  <div class="card"><h2 class="h3">Vorhandene Felder</h2><ul class="list-plain"><?php foreach ($fields as $f): ?><li><?= e($f['label']) ?> <span class="muted small">(<?= e($f['code']) ?>, <?= e($f['field_type']) ?><?= (int) $f['is_required'] ? ', Pflicht' : '' ?>)</span></li><?php endforeach; ?></ul>
      <?= $ui->form('/verwaltung/felder',
        '<div class="wz-step stack" data-title="Beschriftung" data-intro="Wie soll das Feld im Vertragsformular heissen, und wie lautet sein technischer Code (Kleinbuchstaben, Ziffern, Unterstrich)?"><div class="grid-2">' . $ui->field('label', 'Beschriftung', ['required' => true, 'maxlength' => 120]) . $ui->field('code', 'Code', ['required' => true, 'maxlength' => 40, 'pattern' => '[a-z0-9_]+']) . '</div></div>'
        . '<div class="wz-step stack" data-title="Typ und Optionen" data-intro="Welche Art von Angabe wird erfasst? Bei «Auswahl» die Optionen mit Komma trennen."><div class="grid-2">' . $ui->field('field_type', 'Typ', ['type' => 'select', 'options' => ['text' => 'Text', 'number' => 'Zahl', 'date' => 'Datum', 'boolean' => 'Ja/Nein', 'select' => 'Auswahl']]) . $ui->field('options', 'Optionen (Komma, nur Auswahl)') . '</div>'
        . $ui->field('required', 'Pflichtfeld', ['type' => 'checkbox']) . '</div>'
        . '<div class="wz-actions"><button class="btn btn-secondary" type="submit">Hinzufügen</button></div>', false, '', 'wizard-on') ?></div>
</section>
