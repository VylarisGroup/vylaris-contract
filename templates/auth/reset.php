<?php $v->title = 'Neues Passwort'; ?>
<h1 class="h2">Neues Passwort festlegen</h1>
<p class="muted">Mindestens 12 Zeichen. Ein Passwortmanager wird empfohlen.</p>
<?= $ui->form('/passwort/zuruecksetzen/' . rawurlencode($token), $ui->field('password', 'Neues Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
  . $ui->field('password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
  . '<button class="btn btn-primary w-full" type="submit">Passwort speichern</button>') ?>
