<?php $v->title = 'Neue Vorlage'; ?>
<section class="stack">
  <h1 class="h1">Neue Vorlage</h1>
  <form method="post" action="/vorlagen/neu" class="card stack">
    <?= $ui->csrf() ?>
    <div class="grid-2">
      <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) ?>
      <?= $ui->field('contract_type_id', 'Vertragsart', ['type' => 'select', 'options' => $types, 'required' => true, 'empty' => '– wählen –']) ?>
      <?= $ui->field('jurisdiction', 'Rechtsgebiet', ['value' => 'CH', 'maxlength' => 6, 'required' => true]) ?>
    </div>
    <?= $ui->field('body', 'Vorlagentext (HTML mit Platzhaltern)', ['type' => 'textarea', 'rows' => 14, 'required' => true,
      'help' => 'Platzhalter: {{feld}} · Klausel: {{clause:CODE}} · Bedingung: {{#if feld}}…{{else}}…{{/if}}, {{#if feld == "wert"}}…{{/if}}, {{#unless feld}}…{{/unless}}. Eingesetzte Werte werden immer als Text maskiert.']) ?>
    <?= $ui->field('fields', 'Felder (eine Zeile je Feld)', ['type' => 'textarea', 'rows' => 6, 'help' => 'Format: code|Beschriftung|typ|pflicht(1/0)|optionen (Komma). Typen: text, number, date, boolean, select. Beispiel: auftraggeber|Auftraggeber|text|1']) ?>
    <div><button class="btn btn-primary" type="submit"><i class="bx bx-save" aria-hidden="true"></i> Anlegen</button></div>
  </form>
</section>
