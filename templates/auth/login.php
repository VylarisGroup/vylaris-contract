<?php $v->title = 'Anmelden'; ?>
<h1 class="h2">Anmelden</h1>
<?php if (!empty($notice)): ?><div class="alert alert-info" role="status"><?= e($notice) ?></div><?php endif; ?>
<?= $ui->form('/anmelden', $ui->field('email', 'E-Mail-Adresse', ['type' => 'email', 'required' => true, 'autocomplete' => 'username'])
  . $ui->field('password', 'Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
  . '<input type="hidden" name="weiter" value="' . e($next) . '">'
  . '<button class="btn btn-primary w-full" type="submit"><i class="bx bx-log-in" aria-hidden="true"></i> Anmelden</button>') ?>
<p class="small"><a href="/passwort/vergessen">Passwort vergessen?</a></p>
