<?php
$v->title = 'Externe Zugriffe';
$typeLabels = ['counterparty' => 'Gegenpartei', 'audit' => 'Revisionsstelle', 'notary' => 'Notariat'];
$actionLabels = ['read' => 'Lesen', 'download' => 'Herunterladen', 'sign' => 'Unterzeichnen', 'comment' => 'Kommentieren', 'read_versions' => 'Versionen lesen', 'read_history' => 'Verlauf lesen', 'read_evidence' => 'Nachweise lesen', 'download_attachments' => 'Anhänge laden', 'annotate' => 'Vermerke', 'export_report' => 'Bericht exportieren', 'update_case' => 'Fall bearbeiten', 'upload_return' => 'Rückgabe hochladen'];
$typeOpts = [];
foreach (array_keys($types) as $k) {
    $typeOpts[$k] = $typeLabels[$k] ?? $k;
}
?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Externe Zugriffe (Mandate)</h1>
  <p class="muted">Externe Personen sehen ausschliesslich die Verträge und Aktionen, die hier befristet freigegeben werden. Der Zugriff endet automatisch und kann jederzeit widerrufen werden.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Person</th><th scope="col">Art</th><th scope="col">Verträge</th><th scope="col">Gültig</th><th scope="col">Status</th><th scope="col">Aktionen</th></tr></thead><tbody>
  <?php foreach ($mandates as $m): $mid = (int) $m['id']; ?>
    <tr><td><?= e($m['display_name']) ?><br><span class="muted small"><?= e($m['email']) ?><?= $m['organization'] ? ' · ' . e($m['organization']) : '' ?></span></td>
      <td><?= e($typeLabels[$m['mandate_type']] ?? $m['mandate_type']) ?></td><td><?= (int) $m['contract_count'] ?></td>
      <td class="nowrap"><?= e(fdate($m['valid_from'])) ?> – <?= e(fdate($m['valid_until'])) ?></td>
      <td><span class="badge <?= $m['status'] === 'active' ? 'badge-ok' : 'badge-bad' ?>"><?= e($m['status'] === 'active' ? 'aktiv' : ($m['status'] === 'revoked' ? 'widerrufen' : $m['status'])) ?></span></td>
      <td><?php if ($m['status'] === 'active'): ?><div class="row-gap">
        <form method="post" action="/verwaltung/mandate/<?= $mid ?>/widerrufen" class="row-gap"><?= $ui->csrf() ?><label class="sr-only" for="rv<?= $mid ?>">Grund</label><input class="input input-sm" id="rv<?= $mid ?>" name="reason" placeholder="Grund" required maxlength="255"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Zugriff sofort widerrufen?">Widerrufen</button></form>
        <form method="post" action="/verwaltung/mandate/<?= $mid ?>/verlaengern" class="row-gap"><?= $ui->csrf() ?><label class="sr-only" for="ex<?= $mid ?>">Neues Ende</label><input class="input input-sm" id="ex<?= $mid ?>" name="valid_until" type="date" required><button class="btn btn-ghost btn-sm" type="submit">Verlängern</button></form></div><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="card"><h2 class="h3">Neues Mandat</h2>
    <form method="post" action="/verwaltung/mandate" class="stack"><?= $ui->csrf() ?>
      <div class="grid-2">
        <?= $ui->field('type', 'Art des Zugriffs', ['type' => 'select', 'options' => $typeOpts, 'required' => true, 'help' => 'Höchstdauer: Gegenpartei 365, Revision 120, Notariat 180 Tage.']) ?>
        <?= $ui->field('organization', 'Organisation', ['maxlength' => 190]) ?>
        <?= $ui->field('display_name', 'Name der Person', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('email', 'E-Mail', ['type' => 'email', 'required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('valid_from', 'Gültig ab', ['type' => 'date', 'required' => true, 'value' => gmdate('Y-m-d')]) ?>
        <?= $ui->field('valid_until', 'Gültig bis', ['type' => 'date', 'required' => true]) ?>
        <?= $ui->field('responsible_user_id', 'Interne verantwortliche Person', ['type' => 'select', 'options' => $members, 'empty' => '–']) ?>
      </div>
      <fieldset class="fieldset"><legend>Erlaubte Aktionen (müssen zur Art passen)</legend>
        <?php foreach ($types as $tk => $spec): ?><p class="small muted"><?= e($typeLabels[$tk] ?? $tk) ?>:</p><div class="row-gap"><?php foreach ($spec['actions'] as $a): ?><label class="check"><input type="checkbox" name="actions[]" value="<?= e($a) ?>"> <span><?= e($actionLabels[$a] ?? $a) ?></span></label><?php endforeach; ?></div><?php endforeach; ?></fieldset>
      <div class="field"><label class="label" for="cids">Verträge (mehrere mit Strg/Cmd wählen)</label>
        <select class="input" id="cids" name="contract_ids[]" multiple size="8" required><?php foreach ($contracts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?> – <?= e($c['title']) ?></option><?php endforeach; ?></select>
        <?php if ($ui->error('contracts')): ?><p class="field-error" role="alert"><?= e($ui->error('contracts')) ?></p><?php endif; ?></div>
      <div><button class="btn btn-primary" type="submit"><i class="bx bx-user-plus" aria-hidden="true"></i> Mandat anlegen &amp; einladen</button></div>
    </form></div>
</section>
