<?php
$v->title = $cp ? 'Gegenpartei bearbeiten' : 'Neue Gegenpartei';
$g = static fn (string $k): string => (string) ($cp[$k] ?? '');
$action = $cp ? '/gegenparteien/' . (int) $cp['id'] : '/gegenparteien/neu';
?>
<section class="stack">
  <p class="muted small"><a href="/gegenparteien">Gegenparteien</a></p>
  <h1 class="h1"><?= $cp ? e($cp['name']) : 'Neue Gegenpartei' ?></h1>
  <form method="post" action="<?= e($action) ?>" class="card stack"><?= $ui->csrf() ?>
    <div class="grid-2">
      <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 255, 'value' => $g('name')]) ?>
      <?= $ui->field('party_type', 'Art', ['type' => 'select', 'options' => ['company' => 'Unternehmen', 'association' => 'Verein', 'organization' => 'Organisation', 'person' => 'Privatperson'], 'value' => $g('party_type') ?: 'company']) ?>
      <?= $ui->field('legal_form', 'Rechtsform', ['maxlength' => 80, 'value' => $g('legal_form')]) ?>
      <?= $ui->field('registration_id', 'Registernummer (z. B. UID)', ['maxlength' => 80, 'value' => $g('registration_id')]) ?>
      <?= $ui->field('contact_email', 'Kontakt-E-Mail', ['type' => 'email', 'maxlength' => 190, 'value' => $g('contact_email')]) ?>
      <?= $ui->field('address_line', 'Adresse', ['maxlength' => 255, 'value' => $g('address_line')]) ?>
      <?= $ui->field('postal_code', 'PLZ', ['maxlength' => 20, 'value' => $g('postal_code')]) ?>
      <?= $ui->field('city', 'Ort', ['maxlength' => 120, 'value' => $g('city')]) ?>
      <?= $ui->field('country', 'Land (2 Buchstaben)', ['maxlength' => 2, 'value' => $g('country')]) ?>
    </div>
    <?php if ($p->can('sensitive.access')): ?><?= $ui->field('sensitive_data', 'Enthält besonders schützenswerte Angaben (Zugriff nur mit Sonderrecht)', ['type' => 'checkbox', 'value' => (string) ((int) ($cp['sensitive_data'] ?? 0))]) ?><?php endif; ?>
    <p class="muted small">Datensparsamkeit: Erfassen Sie nur, was für die Vertragsabwicklung nötig ist.</p>
    <div class="row-gap"><button class="btn btn-primary" type="submit">Speichern</button><a class="btn btn-ghost" href="/gegenparteien">Abbrechen</a>
    <?php if ($cp): ?><?= $ui->action('/gegenparteien/' . (int) $cp['id'] . '/archivieren', 'Archivieren', 'bx-archive-in', 'btn btn-ghost', 'Gegenpartei archivieren?') ?><?php endif; ?></div>
  </form>
</section>
