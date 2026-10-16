<?php
$v->title = 'Installation';
$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['ok']));
// Welcher Schritt enthält welches Feld? (für das Springen zum ersten Fehler)
$stepOf = ['setup_key' => 0, 'app_url' => 1, 'max_upload_mb' => 1, 'db_host' => 2, 'db_port' => 2, 'db_name' => 2, 'db_user' => 2, 'db_pass' => 2,
    'admin_name' => 3, 'admin_email' => 3, 'admin_password' => 3, 'admin_password2' => 3,
    'smtp_host' => 4, 'smtp_port' => 4, 'smtp_encryption' => 4, 'smtp_user' => 4, 'smtp_pass' => 4, 'mail_from' => 4];
$errStep = null;
foreach (array_keys($errors) as $k) { if (isset($stepOf[$k])) { $errStep = min($errStep ?? 9, $stepOf[$k]); } }
$labels = ['Start', 'Adresse', 'Datenbank', 'Administration', 'E-Mail', 'Abschluss'];
?>
<?php if (!empty($fatal)): ?>
  <h1 class="h2">Installation nicht möglich</h1>
  <div class="alert alert-error" role="alert"><?= e($fatal) ?></div>
<?php elseif (!empty($done)): ?>
  <h1 class="h2"><i class="bx bx-party" aria-hidden="true"></i> Geschafft – die Plattform ist installiert</h1>
  <div class="alert alert-success" role="status">Die Installation wurde gesperrt und der Setup-Schlüssel gelöscht.</div>
  <?php foreach ($warnings as $w): ?><div class="alert alert-warning" role="alert"><?= e($w) ?></div><?php endforeach; ?>
  <h2 class="h3">Das sind die nächsten Schritte</h2>
  <ol class="todo">
    <li><span class="todo-n">1</span><div><strong>Anmelden.</strong> Verwenden Sie das soeben angelegte Konto. Ein zweiter Faktor ist freiwillig; Sie können ihn später unter «Mein Konto» einrichten (Authenticator-App oder Passkey).</div></li>
    <li><span class="todo-n">2</span><div><strong>Cronjob in Plesk einrichten.</strong> Ohne ihn laufen Fristen, Erinnerungen und der E-Mail-Versand nicht. Anleitung: Menü «Hilfe» → «Cronjob einrichten».</div></li>
    <li><span class="todo-n">3</span><div><strong>Ersten Mandanten anlegen.</strong> Unter «System» → «Mandanten». Ein Mandant ist eine Firma oder Organisation mit eigenen Verträgen und Benutzern.</div></li>
    <li><span class="todo-n">4</span><div><strong>Datei <code>.env</code> getrennt sichern.</strong> Sie enthält den Anwendungsschlüssel und die Passphrase der Sicherungen. Ohne sie sind Sicherungen nach einem Serververlust nicht lesbar.</div></li>
  </ol>
  <p><a class="btn btn-primary" href="/anmelden">Zur Anmeldung <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a></p>
<?php else: ?>
  <h1 class="h2">Installation</h1>
  <p class="muted">In sechs kurzen Schritten ist die Plattform startklar. Nach Abschluss ist dieser Assistent dauerhaft gesperrt.</p>
  <?php if (!empty($errors['_'])): ?><div class="alert alert-error" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
  <form method="post" action="/setup" class="stack wizard" id="wizard" data-start="<?= $errStep !== null ? (int) $errStep : 0 ?>" novalidate><?= $ui->csrf() ?>
    <ol class="wizard-progress" aria-label="Fortschritt">
      <?php foreach ($labels as $i => $l): ?><li data-dot="<?= $i ?>"><span class="wizard-dot"><?= $i + 1 ?></span><span class="wizard-label"><?= e($l) ?></span></li><?php endforeach; ?>
    </ol>

    <fieldset class="fieldset wizard-step" data-step="0"><legend>1 · Start</legend>
      <p>Sie brauchen für die Installation:</p>
      <ul class="list-plain bullets">
        <li>die Datei <code>SETUP-SCHLUESSEL.txt</code> aus dem Projektordner (Schutz vor fremder Installation),</li>
        <li>eine <strong>leere Datenbank</strong> samt Benutzer (in Plesk unter «Datenbanken» anlegen),</li>
        <li>optional die Zugangsdaten Ihres E-Mail-Servers.</li>
      </ul>
      <h2 class="h3">Systemprüfung</h2>
      <?php if ($failed === []): ?>
        <div class="alert alert-success" role="status">Alle <?= count($checks) ?> Voraussetzungen sind erfüllt.</div>
      <?php else: ?>
        <div class="alert alert-error" role="alert"><?= count($failed) ?> Voraussetzung(en) fehlen. Bitte beheben und die Seite neu laden:</div>
        <ul class="checks"><?php foreach ($failed as $c): ?><li><span class="badge badge-bad">Fehler</span> <?= e($c['label']) ?> <span class="muted small">– <?= e($c['detail']) ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <details class="details"><summary>Alle Prüfpunkte anzeigen</summary>
        <ul class="checks"><?php foreach ($checks as $c): ?><li><span class="badge <?= $c['ok'] ? 'badge-ok' : 'badge-bad' ?>"><?= $c['ok'] ? 'OK' : 'Fehler' ?></span> <?= e($c['label']) ?> <span class="muted small">– <?= e($c['detail']) ?></span></li><?php endforeach; ?></ul>
      </details>
      <?= $ui->field('setup_key', 'Setup-Schlüssel', ['type' => 'password', 'required' => true, 'autocomplete' => 'off', 'help' => 'Steht in der Datei SETUP-SCHLUESSEL.txt im Projektordner (oberhalb von httpdocs/public).']) ?>
    </fieldset>

    <fieldset class="fieldset wizard-step" data-step="1"><legend>2 · Adresse und Dateien</legend>
      <?= $ui->field('app_url', 'Adresse der Plattform', ['type' => 'url', 'required' => true, 'help' => 'Die Adresse, unter der Sie die Plattform aufrufen, z. B. https://vertraege.meinefirma.ch. Im Betrieb ist HTTPS Pflicht (in Plesk: Let’s Encrypt).']) ?>
      <?= $ui->field('max_upload_mb', 'Maximale Dateigrösse (MB)', ['type' => 'number', 'min' => '1', 'max' => '200', 'required' => true, 'help' => 'Grösste erlaubte Vertragsdatei beim Hochladen. 20 MB passen für die meisten Fälle.']) ?>
    </fieldset>

    <fieldset class="fieldset wizard-step" data-step="2"><legend>3 · Datenbank</legend>
      <p class="muted small">MySQL oder MariaDB, <strong>leer</strong>. Die Angaben finden Sie in Plesk unter «Datenbanken».</p>
      <div class="grid-2">
        <?= $ui->field('db_host', 'Host', ['required' => true, 'help' => 'Meist «localhost».']) ?>
        <?= $ui->field('db_port', 'Port', ['type' => 'number', 'required' => true]) ?>
        <?= $ui->field('db_name', 'Datenbankname', ['required' => true]) ?>
        <?= $ui->field('db_user', 'Benutzer', ['required' => true, 'autocomplete' => 'off']) ?>
        <?= $ui->field('db_pass', 'Passwort', ['type' => 'password', 'autocomplete' => 'off']) ?>
      </div>
      <div class="row-gap"><button type="button" class="btn btn-secondary" data-db-test hidden><i class="bx bx-plug" aria-hidden="true"></i> Verbindung testen</button><span class="small" data-db-result role="status" aria-live="polite"></span></div>
    </fieldset>

    <fieldset class="fieldset wizard-step" data-step="3"><legend>4 · Erste Systemadministration</legend>
      <p class="muted small">Dieses Konto verwaltet die Plattform (Mandanten, Einstellungen, Sicherungen). Verträge bearbeiten Mandanten-Konten.</p>
      <div class="grid-2">
        <?= $ui->field('admin_name', 'Name', ['required' => true]) ?>
        <?= $ui->field('admin_email', 'E-Mail', ['type' => 'email', 'required' => true]) ?>
        <?= $ui->field('admin_password', 'Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'Mindestens 12 Zeichen. Am besten ein Satz aus mehreren Wörtern.']) ?>
        <?= $ui->field('admin_password2', 'Wiederholung', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
      </div>
    </fieldset>

    <fieldset class="fieldset wizard-step" data-step="4"><legend>5 · E-Mail-Versand <span class="badge badge-neutral">optional</span></legend>
      <p class="muted small">Für Einladungen, Passwort-Zurücksetzen und Erinnerungen. Sie können diesen Schritt überspringen und später unter «System → Einstellungen → E-Mail-Server» nachtragen.</p>
      <div class="grid-2">
        <?= $ui->field('smtp_host', 'SMTP-Server') ?>
        <?= $ui->field('smtp_port', 'Port', ['type' => 'number']) ?>
        <?= $ui->field('smtp_encryption', 'Verschlüsselung', ['type' => 'select', 'options' => ['tls' => 'TLS (Port 465)', 'starttls' => 'STARTTLS (Port 587)', 'none' => 'keine (nur Tests)']]) ?>
        <?= $ui->field('smtp_user', 'Benutzer', ['autocomplete' => 'off']) ?>
        <?= $ui->field('smtp_pass', 'Passwort', ['type' => 'password', 'autocomplete' => 'off']) ?>
        <?= $ui->field('mail_from', 'Absenderadresse') ?>
      </div>
    </fieldset>

    <fieldset class="fieldset wizard-step" data-step="5"><legend>6 · Zusammenfassung</legend>
      <p>Bitte prüfen Sie die Angaben. Mit «Installieren» werden die Tabellen angelegt, die Konfiguration geschrieben und das Konto erstellt.</p>
      <dl class="dl" data-summary></dl>
      <p class="muted small">Der Signaturanbieter startet im <strong>Testmodus</strong> (keine rechtsgültigen Signaturen). Die Umstellung erfolgt bewusst später unter «System → Einstellungen → Signatur».</p>
      <noscript><p class="muted small">Ohne JavaScript sind alle Schritte untereinander sichtbar; die Zusammenfassung entfällt.</p></noscript>
      <div><button class="btn btn-primary" type="submit"><i class="bx bx-check-circle" aria-hidden="true"></i> Installieren</button></div>
    </fieldset>

    <div class="wizard-nav" data-nav hidden>
      <button type="button" class="btn btn-secondary" data-prev><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Zurück</button>
      <button type="button" class="btn btn-primary" data-next>Weiter <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
    </div>
  </form>
  <script src="/assets/setup.js" defer></script>
<?php endif; ?>
