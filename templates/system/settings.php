<?php
$v->title = 'Einstellungen';
$groups = [
    'general' => ['Allgemein', 'bx-cog'],
    'mail' => ['E-Mail (SMTP)', 'bx-envelope'],
    'signature' => ['Signaturanbieter', 'bx-pen'],
    'backup' => ['Sicherungen', 'bx-data'],
];
?>
<section class="stack">
  <h1 class="h1">Systemeinstellungen</h1>
  <p class="muted small">Hier gespeicherte Werte haben Vorrang vor der Datei <code>.env</code>; Geheimnisse werden verschlüsselt in der Datenbank abgelegt und nie wieder angezeigt (leer lassen = unverändert). Nicht hier einstellbar: Datenbankzugang, <code>APP_KEY</code>, Speicherort und die Sicherungs-Passphrase – diese bleiben bewusst in der <code>.env</code>.</p>

  <?php foreach ($groups as $gk => [$gl, $gi]): ?>
  <div class="card"><h2 class="h2"><i class="bx <?= e($gi) ?>" aria-hidden="true"></i> <?= e($gl) ?></h2>
    <?php ob_start(); ?>
    <?php foreach ($items as $k => $it): if ($it['group'] !== $gk) { continue; }
        $help = (string) ($it['help'] ?? '');
        if ($k === 'MAX_UPLOAD_MB') { $help .= ' Aktuell: upload_max_filesize ' . $phpLimits['upload'] . ', post_max_size ' . $phpLimits['post'] . '.'; }
        $isSecret = (bool) ($it['secret'] ?? false);
        $opts = ['type' => $isSecret ? 'password' : ($it['type'] === 'number' ? 'number' : ($it['type'] === 'select' ? 'select' : $it['type'])), 'value' => $it['value'] !== '' ? $it['value'] : ($it['default'] ?? ''), 'help' => trim($help . ' Quelle: ' . ($isSecret && $it['isSet'] ? 'gesetzt (' . $it['source'] . ')' : $it['source']) . '.'), 'autocomplete' => 'off'];
        if ($it['type'] === 'select') { $opts['options'] = $it['options']; }
        if ($isSecret) { $opts['placeholder'] = $it['isSet'] ? '•••••••• (unverändert)' : ''; }
    ?>
      <?= $ui->field($k, $it['label'], $opts) ?>
      <?php if ($it['inDb']): ?><div class="field"><label class="check"><input type="checkbox" name="reset[<?= e($k) ?>]" value="1"> <span>»<?= e($it['label']) ?>« auf den .env-Wert zurücksetzen</span></label></div><?php endif; ?>
    <?php endforeach; ?>
    <?php $inner = ob_get_clean(); ?>
    <?= $ui->form('/system/einstellungen/' . $gk, $inner . '<button type="submit" class="btn btn-primary"><i class="bx bx-save" aria-hidden="true"></i> Speichern</button>') ?>

    <?php if ($gk === 'mail'): ?>
      <hr>
      <p class="muted small">Sendet eine Testnachricht an Ihre eigene Adresse (zuerst speichern).</p>
      <?= $mailActive ? $ui->action('/system/einstellungen/testmail', 'Testmail an mich senden', 'bx-send', 'btn btn-secondary') : '<p class="muted">Noch kein SMTP-Server eingetragen.</p>' ?>
    <?php elseif ($gk === 'backup'): ?>
      <hr>
      <p class="small">Sicherungs-Passphrase: <strong><?= $backupPassSet ? 'gesetzt (in .env)' : 'NICHT gesetzt – Sicherungen wären unverschlüsselt' ?></strong>.
      <a href="/system/sicherungen">Sicherungen verwalten →</a></p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <p><a href="/system">← Zurück zur Systemverwaltung</a></p>
</section>
