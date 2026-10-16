<?php $v->title = 'Passwort vergessen'; ?>
<h1 class="h2">Passwort zurücksetzen</h1>
<p class="muted">Wir senden Ihnen einen Link zum Zurücksetzen, sofern ein Konto zu dieser Adresse besteht.</p>
<?= $ui->form('/passwort/vergessen', $ui->field('email', 'E-Mail-Adresse', ['type' => 'email', 'required' => true, 'autocomplete' => 'email'])
  . '<button class="btn btn-primary w-full" type="submit">Link anfordern</button>') ?>
<p class="small"><a href="/anmelden">Zurück zur Anmeldung</a></p>
