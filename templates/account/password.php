<?php $v->title = 'Passwort ändern'; ?>
<section class="stack narrow">
  <h1 class="h1">Passwort ändern</h1>
  <?= $v->partial('partials/account_nav', ['current' => '/konto/passwort']) ?>
    <div class="card">
      <h2 class="h3">Passwort ändern</h2>
      <?= $ui->form('/konto/passwort',
        $ui->field('current_password', 'Aktuelles Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
        . $ui->field('new_password', 'Neues Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'Mindestens 12 Zeichen. Andere Sitzungen werden beendet.'])
        . $ui->field('new_password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
        . '<button class="btn btn-primary" type="submit">Passwort ändern</button>') ?>
    </div>
</section>
