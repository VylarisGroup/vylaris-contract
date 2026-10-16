<?php $v->title = 'Installation'; ?>
<?php if (!empty($fatal)): ?>
  <h1 class="h2">Installation nicht möglich</h1>
  <div class="alert alert-error" role="alert"><?= e($fatal) ?></div>
<?php elseif (!empty($done)): ?>
  <h1 class="h2">Installation abgeschlossen</h1>
  <div class="alert alert-success" role="status">Die Plattform ist eingerichtet und die Installation wurde gesperrt. Der Setup-Schlüssel wurde gelöscht.</div>
  <?php foreach ($warnings as $w): ?><div class="alert alert-warning" role="alert"><?= e($w) ?></div><?php endforeach; ?>
  <ol class="steps">
    <li>Melden Sie sich mit dem soeben angelegten Konto an. Für Systemadministrationen ist ein zweiter Faktor (Authenticator-App) Pflicht und wird beim ersten Login eingerichtet.</li>
    <li>Richten Sie in Plesk den Cronjob ein (siehe <code>docs/CRON.md</code>) – ohne ihn laufen Fristen, Erinnerungen und der E-Mail-Versand nicht.</li>
    <li>Legen Sie unter „System“ den ersten Mandanten an und prüfen Sie die Diagnose.</li>
    <li><strong>Sichern Sie die Datei <code>.env</code> getrennt</strong> (enthält Anwendungsschlüssel und Sicherungs-Passphrase). Ohne sie sind MFA-Geheimnisse und verschlüsselte Sicherungen nach einem Serververlust nicht wiederherstellbar.</li>
  </ol>
  <p><a class="btn btn-primary" href="/anmelden">Zur Anmeldung</a></p>
<?php else: ?>
  <h1 class="h2">Installation</h1>
  <p class="muted">Einmaliger Assistent. Nach Abschluss ist er dauerhaft gesperrt.</p>
  <?php if (!empty($errors['_'])): ?><div class="alert alert-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
  <h2 class="h3">Systemprüfung</h2>
  <ul class="checks">
    <?php foreach ($checks as $c): ?><li><span class="badge <?= $c['ok'] ? 'badge-ok' : 'badge-bad' ?>"><?= $c['ok'] ? 'OK' : 'Fehler' ?></span> <?= e($c['label']) ?> <span class="muted small">– <?= e($c['detail']) ?></span></li><?php endforeach; ?>
  </ul>
  <form method="post" action="/setup" class="stack"><?= $ui->csrf() ?>
    <fieldset class="fieldset"><legend>Schutz</legend>
      <?= $ui->field('setup_key', 'Setup-Schlüssel', ['type' => 'password', 'required' => true, 'autocomplete' => 'off', 'help' => 'Steht in der Datei SETUP-SCHLUESSEL.txt im Projektordner (oberhalb von httpdocs/public).']) ?>
    </fieldset>
    <fieldset class="fieldset"><legend>Anwendung</legend>
      <?= $ui->field('app_url', 'Adresse der Plattform (HTTPS)', ['type' => 'url', 'required' => true]) ?>
      <?= $ui->field('max_upload_mb', 'Maximale Dateigrösse (MB)', ['type' => 'number', 'min' => '1', 'max' => '200', 'required' => true]) ?>
    </fieldset>
    <fieldset class="fieldset"><legend>Datenbank (MySQL / MariaDB, leer)</legend>
      <div class="grid-2">
        <?= $ui->field('db_host', 'Host', ['required' => true]) ?>
        <?= $ui->field('db_port', 'Port', ['type' => 'number', 'required' => true]) ?>
        <?= $ui->field('db_name', 'Datenbankname', ['required' => true]) ?>
        <?= $ui->field('db_user', 'Benutzer', ['required' => true, 'autocomplete' => 'off']) ?>
        <?= $ui->field('db_pass', 'Passwort', ['type' => 'password', 'autocomplete' => 'off']) ?>
      </div>
    </fieldset>
    <fieldset class="fieldset"><legend>Erste Systemadministration</legend>
      <div class="grid-2">
        <?= $ui->field('admin_name', 'Name', ['required' => true]) ?>
        <?= $ui->field('admin_email', 'E-Mail', ['type' => 'email', 'required' => true]) ?>
        <?= $ui->field('admin_password', 'Passwort (min. 12 Zeichen)', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <?= $ui->field('admin_password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
      </div>
    </fieldset>
    <fieldset class="fieldset"><legend>E-Mail-Versand (SMTP, optional – später in .env nachtragbar)</legend>
      <div class="grid-2">
        <?= $ui->field('smtp_host', 'SMTP-Server') ?>
        <?= $ui->field('smtp_port', 'Port', ['type' => 'number']) ?>
        <?= $ui->field('smtp_encryption', 'Verschlüsselung', ['type' => 'select', 'options' => ['tls' => 'TLS (Port 465)', 'starttls' => 'STARTTLS (Port 587)', 'none' => 'keine (nur Tests)']]) ?>
        <?= $ui->field('smtp_user', 'Benutzer', ['autocomplete' => 'off']) ?>
        <?= $ui->field('smtp_pass', 'Passwort', ['type' => 'password', 'autocomplete' => 'off']) ?>
        <?= $ui->field('mail_from', 'Absenderadresse') ?>
      </div>
    </fieldset>
    <p class="muted small">Der Signaturanbieter startet im <strong>Testmodus</strong> (keine rechtsgültigen Signaturen). Die Umstellung auf einen Anbieter erfolgt bewusst später in der .env (siehe docs/SIGNATURE_PROVIDER.md).</p>
    <div><button class="btn btn-primary" type="submit"><i class="bx bx-check-circle" aria-hidden="true"></i> Installieren</button></div>
  </form>
<?php endif; ?>
