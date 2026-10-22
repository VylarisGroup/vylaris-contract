<?php $v->title = 'Mandanten'; ?>
<section class="stack">
  <h1 class="h1">Mandanten</h1>
  <div class="card">
    <p class="muted small">Mandanten werden <strong>nie endgültig gelöscht</strong> (Protokoll und Aufbewahrungspflichten). «Sperren» ist vorübergehend, «Archivieren» legt einen nicht mehr benötigten Mandanten ab: niemand kann sich mehr anmelden, die Daten bleiben erhalten, und er lässt sich jederzeit wiederherstellen.</p>
    <p class="small"><a href="/system/mandanten<?= $showArchived ? '' : '?archiv=1' ?>"><?= $showArchived ? 'Archivierte ausblenden' : 'Archivierte anzeigen' ?></a></p>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Kürzel</th><th scope="col">Mitglieder</th><th scope="col">Verträge</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Aktionen</span></th></tr></thead><tbody>
    <?php foreach ($tenants as $t): if ($t['status'] === 'archived' && !$showArchived) { continue; }
      $lab = ['active' => ['aktiv', 'badge-ok'], 'suspended' => ['gesperrt', 'badge-bad'], 'archived' => ['archiviert', 'badge-warn']][$t['status']]; $tid = (int) $t['id']; ?>
    <tr><td><?= e($t['name']) ?></td><td><?= e($t['slug']) ?></td><td><?= (int) $t['members'] ?></td><td><?= (int) $t['contracts'] ?></td>
      <td><span class="badge <?= e($lab[1]) ?>"><?= e($lab[0]) ?></span></td>
      <td><div class="row-gap">
        <a class="btn btn-ghost btn-sm" href="/system/mandanten?bearbeiten=<?= $tid ?><?= $showArchived ? '&archiv=1' : '' ?>#bearbeiten"><i class="bx bx-edit" aria-hidden="true"></i> Bearbeiten</a>
        <?php if ($t['status'] === 'active'): ?>
          <?= $ui->action('/system/mandanten/' . $tid . '/status', 'Sperren', 'bx-block', 'btn btn-ghost btn-sm', 'Mandant sperren? Alle Benutzer verlieren vorübergehend den Zugriff.', ['status' => 'suspended']) ?>
          <?= $ui->action('/system/mandanten/' . $tid . '/status', 'Archivieren', 'bx-archive-in', 'btn btn-ghost btn-sm', 'Mandant archivieren? Niemand kann sich mehr anmelden; die Daten bleiben erhalten und der Mandant lässt sich wiederherstellen.', ['status' => 'archived']) ?>
        <?php elseif ($t['status'] === 'suspended'): ?>
          <?= $ui->action('/system/mandanten/' . $tid . '/status', 'Aktivieren', 'bx-check', 'btn btn-ghost btn-sm', null, ['status' => 'active']) ?>
          <?= $ui->action('/system/mandanten/' . $tid . '/status', 'Archivieren', 'bx-archive-in', 'btn btn-ghost btn-sm', 'Mandant archivieren?', ['status' => 'archived']) ?>
        <?php else: ?>
          <?= $ui->action('/system/mandanten/' . $tid . '/status', 'Wiederherstellen', 'bx-archive-out', 'btn btn-ghost btn-sm', null, ['status' => 'active']) ?>
        <?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($edit !== null): ?>
    <h3 class="h3 mt" id="bearbeiten">Mandant bearbeiten: <?= e($edit['name']) ?></h3>
    <?= $ui->form('/system/mandanten/' . (int) $edit['id'],
        '<div class="grid-2">' . $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190, 'value' => $edit['name']])
        . $ui->field('slug', 'Kürzel (a–z, 0–9, -)', ['required' => true, 'maxlength' => 61, 'pattern' => '[a-z0-9][a-z0-9-]+', 'value' => $edit['slug'], 'help' => 'Das Kürzel wird für SSO-Direktlinks verwendet; Änderungen machen alte Links ungültig.']) . '</div>'
        . '<div class="row-gap"><button class="btn btn-primary" type="submit">Speichern</button> <a class="btn btn-ghost" href="/system/mandanten">Abbrechen</a></div>', false, 'stack card') ?>
    <?php endif; ?>
    <h3 class="h3 mt">Neuer Mandant</h3>
    <form method="post" action="/system/mandanten" class="stack" data-wizard="auto"><?= $ui->csrf() ?>
      <div class="wz-step stack" data-title="Mandant" data-intro="Wie heisst der Mandant (Organisation), und welches Kürzel soll er erhalten?">
      <div class="grid-2">
        <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('slug', 'Kürzel (a–z, 0–9, -)', ['required' => true, 'maxlength' => 61, 'pattern' => '[a-z0-9][a-z0-9-]+']) ?>
      </div></div>
      <div class="wz-step stack" data-title="Erste Administration" data-intro="Wer verwaltet den Mandanten? Die Person wird per E-Mail eingeladen.">
      <div class="grid-2">
        <?= $ui->field('admin_name', 'Erste Administration – Name', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('admin_email', 'Erste Administration – E-Mail', ['type' => 'email', 'required' => true, 'maxlength' => 190]) ?>
      </div></div>
      <div class="wz-actions"><button class="btn btn-primary" type="submit">Mandant anlegen &amp; einladen</button></div></form></div>

  <p><a href="/system">← Zurück zur Übersicht</a></p>
</section>
