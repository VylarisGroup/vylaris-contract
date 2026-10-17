<?php $v->title = 'Neue Vorlage'; ?>
<section class="stack">
  <h1 class="h1">Neue Vorlage</h1>
  <form method="post" action="/vorlagen/neu" class="card stack" data-wizard="auto">
    <?= $ui->csrf() ?>
    <div class="wz-step stack" data-title="Grunddaten" data-intro="Wie heisst die Vorlage, für welche Vertragsart und welches Rechtsgebiet gilt sie?">
    <div class="grid-2">
      <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) ?>
      <?= $ui->field('contract_type_id', 'Vertragsart', ['type' => 'select', 'options' => $types, 'required' => true, 'empty' => '– wählen –']) ?>
      <?= $ui->field('jurisdiction', 'Rechtsgebiet', ['value' => 'CH', 'maxlength' => 6, 'required' => true]) ?>
    </div></div>
    <div class="wz-step stack" data-title="Text" data-intro="Der Vorlagentext mit Platzhaltern. Die Hilfe unter dem Feld erklärt die Schreibweise.">
    <?= $ui->field('body', 'Vorlagentext (HTML mit Platzhaltern)', ['type' => 'textarea', 'rows' => 14, 'required' => true,
      'help' => 'Platzhalter: {{feld}} · Klausel: {{clause:CODE}} · Bedingung: {{#if feld}}…{{else}}…{{/if}}, {{#if feld == "wert"}}…{{/if}}, {{#unless feld}}…{{/unless}}. Eingesetzte Werte werden immer als Text maskiert.']) ?>
    </div>
    <div class="wz-step stack" data-title="Felder" data-intro="Welche Angaben sollen beim Verwenden der Vorlage abgefragt werden? Optional – ohne Felder bleibt der Text unverändert.">
    <?= $ui->field('fields', 'Felder (eine Zeile je Feld)', ['type' => 'textarea', 'rows' => 6, 'help' => 'Format: code|Beschriftung|typ|pflicht(1/0)|optionen (Komma)|Frage|Hinweis (die letzten drei Angaben sind optional; Frage und Hinweis erscheinen im Fragebogen beim Erstellen eines Vertrags). Typen: text, number, date, boolean, select. Beispiel: auftraggeber|Auftraggeber|text|1']) ?>
    </div>
    <div class="wz-actions"><button class="btn btn-primary" type="submit"><i class="bx bx-save" aria-hidden="true"></i> Anlegen</button></div>
  </form>
</section>
