<?php $v->title = 'Sicherungen'; ?>
<section class="stack">
  <h1 class="h1">Sicherungen</h1>
  <div class="card">
    <p>Eine Sicherung enthält die gesamte Datenbank und alle Dokumente (<?= $passSet ? 'verschlüsselt mit der Passphrase aus der .env' : '<strong>UNVERSCHLÜSSELT – BACKUP_PASSPHRASE in der .env setzen</strong>' ?>). Aufbewahrt werden die letzten <?= (int) $keep ?> Sicherungen. Freier Speicher: <?= $free !== false ? e(number_format($free / 1048576, 0, ',', '’')) . ' MB' : 'unbekannt' ?>.</p>
    <?= $ui->action('/system/sicherungen', 'Sicherung jetzt erstellen', 'bx-data', 'btn btn-primary', 'Jetzt eine Sicherung erstellen? Je nach Datenmenge dauert das einen Moment.') ?>
    <?php if (is_array($result)): ?>
      <p class="mt"><span class="badge <?= $result['ok'] ? 'badge-ok' : 'badge-bad' ?>"><?= $result['ok'] ? 'Prüfung bestanden' : 'Prüfung fehlgeschlagen' ?></span>
        <?= e($result['name']) ?> – <?= (int) $result['entries'] ?> Einträge<?= $result['errors'] ? ': ' . e(implode('; ', array_map('strval', $result['errors']))) : '' ?><?= $result['pruned'] > 0 ? ' · alte Sicherungen entfernt: ' . (int) $result['pruned'] : '' ?></p>
    <?php endif; ?>
  </div>
  <div class="card"><h2 class="h2">Vorhandene Sicherungen</h2>
    <?php if ($files === []): ?><p class="muted">Noch keine Sicherung vorhanden.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Datei</th><th scope="col">Erstellt (UTC)</th><th scope="col">Grösse</th><th scope="col">Verschlüsselt</th><th scope="col"><span class="sr-only">Aktionen</span></th></tr></thead><tbody>
    <?php foreach ($files as $f): ?><tr>
      <td><?= e($f['name']) ?></td><td><?= e(gmdate('d.m.Y H:i', $f['mtime'])) ?></td><td><?= e(number_format($f['bytes'] / 1048576, 1, ',', '’')) ?> MB</td>
      <td><?= $f['encrypted'] ? 'ja' : '<span class="badge badge-warn">nein</span>' ?></td>
      <td><a class="btn btn-ghost btn-sm" href="/system/sicherungen/<?= e($f['id']) ?>"><i class="bx bx-download" aria-hidden="true"></i> Herunterladen</a>
        <?= $ui->action('/system/sicherungen/' . $f['id'] . '/pruefen', 'Prüfen', 'bx-check-shield', 'btn btn-ghost btn-sm') ?>
        <?= $ui->action('/system/sicherungen/' . $f['id'] . '/loeschen', 'Löschen', 'bx-trash', 'btn btn-ghost btn-sm', 'Diese Sicherung endgültig löschen?') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card"><h2 class="h2">Aufbewahrung</h2>
    <?= $v->partial('partials/settings_form', ['group' => 'backup', 'items' => $items]) ?>
    <p class="small muted">Sicherungs-Passphrase: <strong><?= $passSet ? 'gesetzt (in .env)' : 'NICHT gesetzt – Sicherungen wären unverschlüsselt' ?></strong>.</p>
  </div>
  <div class="card"><h2 class="h2">Wiederherstellung</h2>
    <p class="small">Eine Wiederherstellung überschreibt nie die laufende Datenbank und ist deshalb bewusst nicht per Klick möglich. Sie erfolgt auf der Kommandozeile in eine separate Datenbank, siehe <code>docs/BACKUP_RESTORE.md</code>. »Prüfen« kontrolliert Entschlüsselung und alle Hashes des Archivs.</p>
  </div>
  <p><a href="/system">← Zurück zur Systemverwaltung</a></p>
</section>
