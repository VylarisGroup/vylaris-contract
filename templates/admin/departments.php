<?php $v->title = 'Abteilungen'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Abteilungen</h1>
  <?php foreach ($departments as $d): ?>
    <div class="card"><div class="row-between"><strong><?= e($d['name']) ?></strong><span class="muted small"><?= (int) $d['members'] ?> Mitglieder · <?= (int) $d['contracts'] ?> Verträge</span></div>
      <form method="post" action="/verwaltung/abteilungen/<?= (int) $d['id'] ?>/mitglied" class="row-gap"><?= $ui->csrf() ?>
        <label class="sr-only" for="u<?= (int) $d['id'] ?>">Benutzer</label><select class="input input-sm" id="u<?= (int) $d['id'] ?>" name="user_id"><?php foreach ($members as $uid => $label): ?><option value="<?= (int) $uid ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        <button class="btn btn-secondary btn-sm" type="submit" name="member" value="1">Hinzufügen</button><button class="btn btn-ghost btn-sm" type="submit" name="member" value="0">Entfernen</button></form></div>
  <?php endforeach; ?>
  <div class="card"><h2 class="h3">Neue Abteilung</h2><?= $ui->form('/verwaltung/abteilungen', $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) . '<button class="btn btn-primary" type="submit">Anlegen</button>') ?></div>
</section>
