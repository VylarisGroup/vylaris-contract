<?php
$edit = $mode === 'edit';
$v->title = $edit ? 'Vertrag bearbeiten' : 'Neuer Vertrag';
$val = static fn (string $k, mixed $d = '') => $c[$k] ?? $d;
$isDraft = !$edit || ($c['status'] ?? '') === 'draft';
$action = $edit ? '/vertraege/' . (int) $c['id'] . '/bearbeiten' : '/vertraege/neu';
$memberOpts = $members;
$parties = $c['parties'] ?? [];
$rows = max(2, count($parties) + 1);
?>
<section class="stack">
  <h1 class="h1"><?= $edit ? 'Vertrag bearbeiten' : 'Neuen Vertrag anlegen' ?></h1>
  <?php if ($edit && !$isDraft): ?><div class="alert alert-info" role="status">Der Vertrag ist nicht mehr im Entwurf. Vertragsinhaltliche Angaben sind gesperrt; Änderungen erfolgen über eine neue Version oder einen Nachtrag.</div><?php endif; ?>
  <form method="post" action="<?= e($action) ?>" class="card stack" data-wizard="<?= $edit ? 'on' : 'auto' ?>">
    <?= $ui->csrf() ?>
    <div class="wz-step stack" data-title="Grundlagen" data-intro="Wie heisst der Vertrag, um was für einen Vertrag handelt es sich und wer ist zuständig?">
    <div class="grid-2">
      <?= $ui->field('title', 'Titel', ['required' => true, 'maxlength' => 255, 'value' => $val('title')]) ?>
      <?= $ui->field('contract_type_id', 'Vertragsart', ['type' => 'select', 'required' => true, 'options' => $types, 'empty' => '– wählen –', 'value' => $val('contract_type_id')]) ?>
      <?= $ui->field('department_id', 'Abteilung', ['type' => 'select', 'options' => $departments, 'empty' => 'Mandantenweit (keine Abteilung)', 'value' => $val('department_id')]) ?>
      <?= $ui->field('owner_user_id', 'Zuständige Person', ['type' => 'select', 'options' => $memberOpts, 'value' => $val('owner_user_id', (string) $p->userId)]) ?>
    </div></div>
    <div class="wz-step stack" data-title="Laufzeit" data-intro="Wann beginnt und endet der Vertrag, und wie kann er gekündigt oder verlängert werden? Daraus berechnet die Plattform die Fristen.">
    <div class="grid-2">
      <?= $ui->field('start_date', 'Beginn', ['type' => 'date', 'value' => $val('start_date')]) ?>
      <?= $ui->field('end_date', 'Ende', ['type' => 'date', 'value' => $val('end_date')]) ?>
      <?= $ui->field('notice_period_days', 'Kündigungsfrist (Tage)', ['type' => 'number', 'min' => '0', 'max' => '3650', 'value' => $val('notice_period_days')]) ?>
      <?= $ui->field('auto_renew', 'Verlängert sich automatisch', ['type' => 'checkbox', 'value' => $val('auto_renew', '0')]) ?>
      <?= $ui->field('renewal_period_days', 'Verlängerung um (Tage)', ['type' => 'number', 'min' => '0', 'max' => '3650', 'value' => $val('renewal_period_days')]) ?>
      <?= $ui->field('renewal_terms', 'Verlängerungsbedingungen', ['value' => $val('renewal_terms'), 'maxlength' => 500]) ?>
    </div></div>
    <div class="wz-step stack" data-title="Wert und Recht" data-intro="Vertragswert, Zahlungsbedingungen und rechtliche Rahmenbedingungen. Alles ist optional und kann später ergänzt werden.">
    <div class="grid-2">
      <?= $ui->field('value_amount', 'Vertragswert', ['inputmode' => 'decimal', 'value' => $val('value_amount'), 'help' => 'z. B. 12500.00']) ?>
      <?= $ui->field('value_currency', 'Währung', ['value' => $val('value_currency', 'CHF'), 'maxlength' => 3]) ?>
      <?= $ui->field('governing_law', 'Anwendbares Recht', ['value' => $val('governing_law'), 'maxlength' => 120]) ?>
      <?= $ui->field('venue', 'Gerichtsstand', ['value' => $val('venue'), 'maxlength' => 190]) ?>
    </div>
    <?= $ui->field('payment_terms', 'Zahlungsbedingungen', ['value' => $val('payment_terms'), 'maxlength' => 500]) ?>
    </div>
    <?php if ($isDraft): ?>
    <div class="wz-step stack" data-title="Parteien" data-intro="Wer sind die Vertragsparteien, und wer unterzeichnet? Gegenparteien legen Sie unter «Gegenparteien» an.">
    <?php include __DIR__ . '/_parties.php'; ?>
    </div>
    <?php endif; ?>
    <div class="wz-step stack" data-title="Weitere Angaben" data-intro="Notizen, Schlagworte und mandantenspezifische Zusatzfelder.">
    <?= $ui->field('description', 'Beschreibung / interne Notiz', ['type' => 'textarea', 'rows' => 3, 'value' => $val('description')]) ?>
    <?= $ui->field('tags', 'Tags (durch Komma getrennt)', ['value' => implode(', ', (array) ($c['tags'] ?? []))]) ?>

    <?php if ($customFields !== []): ?>
    <fieldset class="fieldset"><legend>Zusatzfelder</legend>
      <div class="grid-2">
      <?php foreach ($customFields as $cf):
          $opts = $cf['field_type'] === 'select' ? array_combine($o = (array) json_decode((string) $cf['options'], true), $o) : [];
          $cv = ($customValues ?? [])[$cf['code']] ?? '';
          $type = ['number' => 'number', 'date' => 'date', 'boolean' => 'select', 'select' => 'select'][$cf['field_type']] ?? 'text';
          $o2 = ['type' => $type, 'value' => $cv, 'required' => (int) $cf['is_required'] === 1];
          if ($cf['field_type'] === 'select') { $o2['options'] = $opts; $o2['empty'] = '–'; }
          if ($cf['field_type'] === 'boolean') { $o2['options'] = ['1' => 'Ja', '0' => 'Nein']; $o2['empty'] = '–'; }
          echo str_replace('name="custom_' . $cf['code'] . '"', 'name="custom[' . e($cf['code']) . ']"', $ui->field('custom_' . $cf['code'], $cf['label'], $o2));
      endforeach; ?>
      </div>
    </fieldset>
    <?php endif; ?>

    </div>
    <div class="row-gap wz-actions"><button class="btn btn-primary" type="submit"><i class="bx bx-save" aria-hidden="true"></i> Speichern</button><a class="btn btn-ghost" href="<?= $edit ? '/vertraege/' . (int) $c['id'] : '/vertraege' ?>">Abbrechen</a></div>
  </form>

  <?php if ($edit && ($body['state'] ?? '') !== '' ): ?>
  <form method="post" action="/vertraege/<?= (int) $c['id'] ?>/text" class="card stack">
    <?= $ui->csrf() ?>
    <h2 class="h2">Vertragstext</h2>
    <?php if (($body['state'] ?? 'draft') === 'locked'): ?><p class="alert alert-info">Die aktuelle Version ist gesperrt. Beim Speichern entsteht eine <strong>neue Version</strong>; frühere Freigaben dieser Version werden ungültig.</p><?php endif; ?>
    <p class="muted small">Erlaubt ist einfaches HTML: Überschriften (h1–h3), Absätze (p), Listen (ul/ol/li), fett/kursiv, Tabellen. Skripte und Formatierungsattribute werden entfernt. Alternativ können Sie ein Word-Dokument importieren.</p>
    <?= $ui->field('body', 'Text (HTML)', ['type' => 'textarea', 'rows' => 18, 'value' => $body['body_html'] ?? '']) ?>
    <?= $ui->field('change_note', 'Änderungsnotiz', ['maxlength' => 255]) ?>
    <div><button class="btn btn-primary" type="submit"><i class="bx bx-save" aria-hidden="true"></i> Text speichern</button></div>
  </form>
  <?php endif; ?>
</section>
