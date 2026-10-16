<?php $v->title = 'Compliance'; ?>
<section class="stack">
  <h1 class="h1">Compliance &amp; Datenschutz</h1>
  <div class="alert alert-info" role="note">Diese Plattform stellt Werkzeuge bereit (rechtliche Sperren, Aufbewahrungsregeln mit Begründung, Auskunfts- und Löschanfragen). Sie <strong>garantiert keine Rechtskonformität</strong> und kennt keine universelle Aufbewahrungsfrist: Fristen und Rechtsgrundlagen sind durch Ihre Rechts- bzw. Datenschutzstelle festzulegen und zu bestätigen.</div>

  <?php if ($p->can('legalhold.manage')): ?>
  <div class="card" id="sperren"><h2 class="h2"><i class="bx bx-lock-alt" aria-hidden="true"></i> Rechtliche Sperren (Legal Hold)</h2>
    <?php if ($holds === []): ?><p class="muted">Keine Sperren.</p><?php endif; ?>
    <div class="table-wrap"><table class="table table-sm"><tbody>
    <?php foreach ($holds as $h): ?><tr><td><?= $h['contract_number'] ? e($h['contract_number']) : 'Gegenpartei #' . (int) $h['counterparty_id'] ?></td><td><?= e($h['reason']) ?></td><td><?= e(fdt($h['placed_at'])) ?></td>
      <td><?php if ($h['released_at'] === null): ?>
        <form method="post" action="/compliance/sperren/<?= (int) $h['id'] ?>/aufheben" class="row-gap"><?= $ui->csrf() ?><label class="sr-only" for="hr<?= (int) $h['id'] ?>">Grund</label><input class="input input-sm" id="hr<?= (int) $h['id'] ?>" name="reason" placeholder="Grund der Aufhebung" required><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Sperre aufheben?">Aufheben</button></form>
        <?php else: ?><span class="muted small">aufgehoben <?= e(fdt($h['released_at'])) ?></span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post" action="/compliance/sperren" class="stack"><?= $ui->csrf() ?>
      <div class="grid-2">
        <div class="field"><label class="label" for="hc">Vertrag</label><select class="input" id="hc" name="contract_id"><option value="">–</option><?php foreach ($contracts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?> – <?= e($c['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="hp">oder Gegenpartei</label><select class="input" id="hp" name="counterparty_id"><option value="">–</option><?php foreach ($counterparties as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?= $ui->field('reason', 'Grund der Sperre', ['type' => 'textarea', 'rows' => 2, 'required' => true]) ?>
      <div><button class="btn btn-primary" type="submit">Sperre setzen</button></div></form></div>
  <?php endif; ?>

  <?php if ($p->can('retention.manage')): ?>
  <div class="card" id="aufbewahrung"><h2 class="h2"><i class="bx bx-archive" aria-hidden="true"></i> Aufbewahrungsregeln</h2>
    <?php foreach ($policies as $r): ?><div class="sub-card"><div class="row-between"><strong><?= e($r['name']) ?></strong>
      <span class="badge <?= $r['legal_review_status'] === 'reviewed' ? 'badge-ok' : 'badge-warn' ?>"><?= $r['legal_review_status'] === 'reviewed' ? 'rechtlich geprüft' : 'zu prüfen' ?></span></div>
      <p class="small"><?= (int) $r['retention_years'] ?> Jahre ab <?= e(['contract_end' => 'Vertragsende', 'archived_at' => 'Archivierung', 'signed_at' => 'Unterzeichnung'][$r['start_event']] ?? $r['start_event']) ?> · <?= e($r['jurisdiction']) ?> · <?= e($r['type_name'] ?? 'alle Vertragsarten') ?> · danach: <?= $r['on_expiry'] === 'delete' ? 'Löschung vorschlagen' : 'prüfen' ?></p>
      <p class="small muted">Zweck: <?= e($r['purpose']) ?> · Rechtsgrundlage: <?= e($r['legal_basis']) ?></p>
      <?php if ($r['legal_review_status'] !== 'reviewed'): ?><?= $ui->action('/compliance/aufbewahrung/' . (int) $r['id'] . '/geprueft', 'Als rechtlich geprüft bestätigen', 'bx-check', 'btn btn-secondary btn-sm', 'Bestätigen Sie, dass Rechtsgrundlage und Dauer durch eine fachkundige Stelle geprüft wurden?') ?><?php endif; ?></div><?php endforeach; ?>
    <h3 class="h3 mt">Neue Regel</h3>
    <form method="post" action="/compliance/aufbewahrung" class="stack"><?= $ui->csrf() ?>
      <div class="grid-2">
        <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('contract_type_id', 'Vertragsart', ['type' => 'select', 'options' => $types, 'empty' => 'Alle']) ?>
        <?= $ui->field('retention_years', 'Aufbewahrung (Jahre)', ['type' => 'number', 'min' => '1', 'max' => '100', 'required' => true]) ?>
        <?= $ui->field('jurisdiction', 'Rechtsgebiet', ['required' => true, 'value' => 'CH', 'maxlength' => 6]) ?>
        <?= $ui->field('start_event', 'Fristbeginn', ['type' => 'select', 'options' => ['contract_end' => 'Vertragsende', 'archived_at' => 'Archivierung', 'signed_at' => 'Unterzeichnung']]) ?>
        <?= $ui->field('on_expiry', 'Nach Ablauf', ['type' => 'select', 'options' => ['review' => 'Zur Prüfung vorlegen', 'delete' => 'Löschung vorschlagen']]) ?>
      </div>
      <?= $ui->field('purpose', 'Zweck der Aufbewahrung', ['required' => true, 'maxlength' => 255]) ?>
      <?= $ui->field('legal_basis', 'Rechtsgrundlage (z. B. Gesetzesartikel)', ['required' => true, 'maxlength' => 500]) ?>
      <div><button class="btn btn-primary" type="submit">Regel speichern</button></div></form>
    <?php if ($queue !== []): ?>
      <h3 class="h3 mt">Aufbewahrungsfrist abgelaufen (zur Prüfung)</h3>
      <div class="table-wrap"><table class="table table-sm"><tbody><?php foreach ($queue as $q): ?><tr><td><a href="/vertraege/<?= (int) $q['id'] ?>"><?= e($q['contract_number']) ?></a> <?= e($q['title']) ?></td><td><?= e(fdate($q['retain_until'] ?? null)) ?></td>
        <td><?php if ($p->can('retention.manage')): ?><form method="post" action="/compliance/loeschen/<?= (int) $q['id'] ?>" class="row-gap"><?= $ui->csrf() ?><label class="sr-only" for="pc<?= (int) $q['id'] ?>">Vertragsnummer zur Bestätigung</label><input class="input input-sm" id="pc<?= (int) $q['id'] ?>" name="confirm_number" placeholder="Nummer eintippen" required><button class="btn btn-danger btn-sm" type="submit" data-confirm="Vertrag und Dokumente UNWIDERRUFLICH löschen?">Endgültig löschen</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($p->can('privacy.manage')): ?>
  <div class="card" id="anfragen"><h2 class="h2"><i class="bx bx-id-card" aria-hidden="true"></i> Auskunfts- und Löschanfragen</h2>
    <?php if ($requests === []): ?><p class="muted">Keine Anfragen.</p><?php endif; ?>
    <ul class="list-plain"><?php foreach ($requests as $r): ?><li><a href="/compliance/anfragen/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?> <?= $r['request_type'] === 'access' ? 'Auskunft' : 'Löschung' ?></a> – <?= e($r['subject_description']) ?> <span class="badge badge-neutral"><?= e($r['status']) ?></span> <span class="muted small">Hinweisfrist bis <?= e(fdate($r['due_hint'])) ?> (Fristen prüfen lassen)</span></li><?php endforeach; ?></ul>
    <form method="post" action="/compliance/anfragen" class="stack"><?= $ui->csrf() ?>
      <div class="grid-2">
        <?= $ui->field('type', 'Art', ['type' => 'select', 'options' => ['access' => 'Auskunft', 'erasure' => 'Löschung / Anonymisierung']]) ?>
        <?= $ui->field('counterparty_id', 'Betroffene Gegenpartei (falls erfasst)', ['type' => 'select', 'options' => array_column($counterparties, 'name', 'id'), 'empty' => '–']) ?>
      </div>
      <?= $ui->field('subject', 'Betroffene Person / Beschreibung', ['required' => true, 'maxlength' => 500]) ?>
      <div><button class="btn btn-primary" type="submit">Anfrage erfassen</button></div></form></div>
  <?php endif; ?>
</section>
