<?php
$v->title = 'Vorlage ' . $t['name'];
$id = (int) $t['id'];
$cur = $t['current'];
$approved = ($cur['review_status'] ?? '') === 'approved';
?>
<section class="stack">
  <p class="muted small"><a href="/vorlagen">Vorlagen</a></p>
  <div class="row-between"><h1 class="h1"><?= e($t['name']) ?></h1>
    <span class="badge <?= $approved ? 'badge-ok' : 'badge-warn' ?>"><?= e(['approved' => 'fachlich freigegeben', 'in_review' => 'in Prüfung', 'unreviewed' => 'nicht geprüft'][$cur['review_status']] ?? $cur['review_status']) ?></span></div>
  <?php if (!$approved): ?><div class="alert alert-warning" role="note">Diese Vorlage ist nicht fachlich freigegeben. Verträge daraus entstehen nur mit ausdrücklicher Bestätigung. Prüfen Sie Vorlagen vor dem Einsatz durch eine fachkundige Person (Vier-Augen-Prinzip).</div><?php endif; ?>

  <div class="card"><h2 class="h2">Vertrag aus Vorlage erstellen</h2>
    <form method="post" action="/vorlagen/<?= $id ?>/verwenden" class="stack">
      <?= $ui->csrf() ?>
      <div class="grid-2">
        <?= $ui->field('title', 'Titel des Vertrags', ['required' => true, 'maxlength' => 255, 'value' => $t['name']]) ?>
        <?= $ui->field('department_id', 'Abteilung', ['type' => 'select', 'options' => $departments, 'empty' => 'Mandantenweit']) ?>
        <?= $ui->field('start_date', 'Beginn', ['type' => 'date']) ?>
        <?= $ui->field('end_date', 'Ende', ['type' => 'date']) ?>
      </div>
      <?php foreach ($cur['field_schema'] as $f):
          $o = ['required' => !empty($f['required']), 'value' => $values[$f['code']] ?? ''];
          if ($f['type'] === 'number') { $o['type'] = 'number'; $o['step'] = 'any'; }
          if ($f['type'] === 'date') { $o['type'] = 'date'; }
          if ($f['type'] === 'select') { $o['type'] = 'select'; $o['options'] = array_combine($f['options'], $f['options']); $o['empty'] = '–'; }
          if ($f['type'] === 'boolean') { $o['type'] = 'select'; $o['options'] = ['1' => 'Ja', '0' => 'Nein']; $o['empty'] = '–'; }
          echo str_replace('name="v_' . $f['code'] . '"', 'name="v[' . e($f['code']) . ']"', $ui->field('v_' . $f['code'], $f['label'], $o));
      endforeach; ?>
      <?php if (!$approved): ?><?= $ui->field('allow_unreviewed', 'Ich bestätige: Die Vorlage ist nicht freigegeben, ich verwende sie auf eigene Verantwortung.', ['type' => 'checkbox']) ?><?php endif; ?>
      <div class="row-gap">
        <button class="btn btn-primary" type="submit"><i class="bx bx-file-plus" aria-hidden="true"></i> Vertragsentwurf erstellen</button>
        <button class="btn btn-secondary" type="submit" formaction="/vorlagen/<?= $id ?>/vorschau">Vorschau</button>
      </div>
    </form>
    <?php if ($preview !== null): ?>
      <h3 class="h3 mt">Vorschau</h3>
      <?php foreach ($preview['warnings'] as $w): ?><p class="alert alert-warning"><?= e($w) ?></p><?php endforeach; ?>
      <div class="doc-preview"><?= $preview['html'] ?></div>
    <?php endif; ?>
  </div>

  <?php if ($p->can('template.manage')): ?>
  <div class="card"><h2 class="h2">Neue Version erfassen</h2>
    <form method="post" action="/vorlagen/<?= $id ?>/version" class="stack">
      <?= $ui->csrf() ?>
      <?= $ui->field('body', 'Vorlagentext', ['type' => 'textarea', 'rows' => 14, 'value' => $cur['body'], 'required' => true]) ?>
      <?= $ui->field('fields', 'Felder', ['type' => 'textarea', 'rows' => 6, 'value' => $schemaText, 'help' => 'code|Beschriftung|typ|pflicht|optionen']) ?>
      <div><button class="btn btn-secondary" type="submit">Als neue Version speichern</button></div>
    </form>
    <h3 class="h3 mt">Prüfung</h3>
    <div class="row-gap">
      <?php if (in_array($cur['review_status'], ['unreviewed'], true)): ?><?= $ui->action('/vorlagen/' . $id . '/pruefung', 'Zur fachlichen Prüfung einreichen', 'bx-send', 'btn btn-secondary') ?><?php endif; ?>
      <?= $ui->action('/vorlagen/' . $id . '/archivieren', 'Archivieren', 'bx-archive-in', 'btn btn-ghost', 'Vorlage archivieren?') ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($p->can('template.approve') && $cur['review_status'] === 'in_review'): ?>
  <div class="card"><h2 class="h3">Entscheid zur Prüfung</h2>
    <p class="muted small">Die Person, die die aktuelle Version erstellt hat, kann sie nicht selbst freigeben.</p>
    <form method="post" action="/vorlagen/<?= $id ?>/entscheid" class="stack"><?= $ui->csrf() ?>
      <?= $ui->field('comment', 'Kommentar', ['type' => 'textarea', 'rows' => 2]) ?>
      <div class="row-gap"><button class="btn btn-primary" type="submit" name="decision" value="approve">Freigeben</button><button class="btn btn-danger" type="submit" name="decision" value="reject">Ablehnen</button></div></form></div>
  <?php endif; ?>
  <div class="card"><h2 class="h3">Versionen</h2><ul class="list-plain"><?php foreach ($t['versions'] as $x): ?><li>v<?= (int) $x['version_no'] ?> · <?= e($x['review_status']) ?> · <?= e(fdt($x['created_at'])) ?> · <code class="hash"><?= e(substr((string) $x['content_hash'], 0, 12)) ?>…</code></li><?php endforeach; ?></ul>
    <p class="small muted">Verfügbare Klauseln: <?= e(implode(', ', array_map(static fn (array $c): string => $c['code'] . ($c['review_status'] === 'approved' ? '' : ' (ungeprüft)'), $clauses))) ?: '–' ?></p></div>
</section>
