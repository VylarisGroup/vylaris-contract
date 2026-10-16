<?php $v->title = 'Zweiter Faktor'; ?>
<h1 class="h2">Bestätigung mit zweitem Faktor</h1>
<p class="muted">Geben Sie den 6-stelligen Code Ihrer Authenticator-App ein, oder einen Ihrer Wiederherstellungscodes.</p>
<?= $ui->form('/anmelden/mfa', $ui->field('code', 'Code', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'text', 'maxlength' => 20])
  . '<button class="btn btn-primary w-full" type="submit">Bestätigen</button>') ?>
<p class="small"><a href="/anmelden">Abbrechen</a></p>
