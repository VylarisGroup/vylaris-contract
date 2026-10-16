<?php $v->title = 'Einladung annehmen'; ?>
<h1 class="h2">Konto aktivieren</h1>
<p class="muted">Legen Sie ein Passwort fest (mindestens 12 Zeichen). Mit der Aktivierung wird auch Ihre E-Mail-Adresse bestätigt.</p>
<?= $ui->form('/einladung/' . rawurlencode($token), $ui->field('password', 'Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
  . $ui->field('password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
  . '<button class="btn btn-primary w-full" type="submit">Konto aktivieren</button>') ?>
