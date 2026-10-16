<?php $v->title = $pageTitle; ?>
<section class="stack narrow">
  <h1 class="h1"><?= e($pageTitle) ?></h1>
  <p class="muted"><?= e($intro) ?> Hier gespeicherte Werte haben Vorrang vor der Datei <code>.env</code>; Geheimnisse werden verschlüsselt in der Datenbank abgelegt und nie wieder angezeigt (leer lassen = unverändert).</p>
  <div class="card">
    <?= $v->partial('partials/settings_form', ['group' => $group, 'items' => $items, 'phpLimits' => $phpLimits]) ?>
    <?php if ($group === 'mail'): ?>
      <hr>
      <p class="muted small">Sendet eine Testnachricht an Ihre eigene Adresse (zuerst speichern).</p>
      <?= $mailActive ? $ui->action('/system/einstellungen/testmail', 'Testmail an mich senden', 'bx-send', 'btn btn-secondary') : '<p class="muted">Noch kein SMTP-Server eingetragen.</p>' ?>
    <?php elseif ($group === 'general'): ?>
      <hr>
      <p class="muted small">Nicht hier einstellbar: Datenbankzugang, <code>APP_KEY</code>, Speicherort und die Sicherungs-Passphrase – diese bleiben bewusst in der <code>.env</code>.</p>
    <?php endif; ?>
  </div>
  <p><a href="/system">← Zurück zur Systemverwaltung</a></p>
</section>
