<?php $v->title = 'Mandanten'; ?>
<section class="stack">
  <h1 class="h1">Mandanten</h1>
  <div class="card">
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Kürzel</th><th scope="col">Mitglieder</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Aktion</span></th></tr></thead><tbody>
    <?php foreach ($tenants as $t): ?><tr><td><?= e($t['name']) ?></td><td><?= e($t['slug']) ?></td><td><?= (int) $t['members'] ?></td>
      <td><span class="badge <?= $t['status'] === 'active' ? 'badge-ok' : 'badge-bad' ?>"><?= $t['status'] === 'active' ? 'aktiv' : 'gesperrt' ?></span></td>
      <td><?= $t['status'] === 'active'
        ? $ui->action('/system/mandanten/' . (int) $t['id'] . '/status', 'Sperren', 'bx-block', 'btn btn-ghost btn-sm', 'Mandant sperren? Alle Benutzer verlieren den Zugriff.', ['status' => 'suspended'])
        : $ui->action('/system/mandanten/' . (int) $t['id'] . '/status', 'Aktivieren', 'bx-check', 'btn btn-ghost btn-sm', null, ['status' => 'active']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
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
