<?php $v->title = 'Aktualisierung'; ?>
<section class="stack">
  <h1 class="h1">Systemaktualisierung</h1>
  <div class="card">
    <p>Installierte Version: <strong><?= e($current) ?></strong> · Quelle: <code>github.com/<?= e($repo) ?></code> (Releases, fest hinterlegt)</p>
    <?= $ui->action('/system/aktualisierung/pruefen', 'Auf neue Version prüfen', 'bx-refresh', 'btn btn-secondary') ?>
  </div>

  <?php if (is_array($latest)): ?>
  <div class="card"><h2 class="h2">Neueste Release: <?= e($latest['tag']) ?></h2>
    <p class="muted small">Geprüft am <?= e((string) $latest['checked_at']) ?> UTC · veröffentlicht <?= e((string) $latest['published_at']) ?><?php if ($latest['url'] !== ''): ?> · <a href="<?= e($latest['url']) ?>" rel="noopener noreferrer" target="_blank">auf GitHub ansehen</a><?php endif; ?></p>
    <?php if ($latest['notes'] !== ''): ?><pre class="small" style="white-space:pre-wrap"><?= e($latest['notes']) ?></pre><?php endif; ?>
    <?php if (version_compare($latest['version'], $current, '>')): ?>
      <div class="alert alert-warn" role="note">Ablauf: Sicherung (Datenbank + Dokumente) → Wartungsmodus → Programmdateien ersetzen → Datenbank-Migrationen → bei Fehler Rückspielen des alten Programmstands. <strong>Es erfolgt keine kryptographische Prüfung des Pakets</strong>; die SHA-256 wird nur protokolliert. Die Release muss von Ihnen als vertrauenswürdig eingestuft sein.</div>
      <?= $ui->action('/system/aktualisierung/installieren', 'Auf ' . $latest['version'] . ' aktualisieren', 'bx-cloud-download', 'btn btn-primary', 'Jetzt auf Version ' . $latest['version'] . ' aktualisieren? Das System ist währenddessen kurz nicht erreichbar.', ['version' => $latest['version']]) ?>
    <?php else: ?><p><span class="badge badge-ok">aktuell</span> Es ist keine neuere Version verfügbar.</p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (is_array($run)): ?>
  <div class="card"><h2 class="h2">Letzter Versuch (<?= e((string) $run['at']) ?> UTC): <?= $run['ok'] ? 'erfolgreich' : 'fehlgeschlagen' ?></h2>
    <ul><?php foreach ((array) ($run['steps'] ?? []) as $s): ?><li><?= e((string) $s) ?></li><?php endforeach; ?></ul>
    <?php if (!$run['ok']): ?><p><strong><?= e((string) ($run['error'] ?? '')) ?></strong> <?= e((string) ($run['rollback'] ?? '')) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
  <p><a href="/system">← Zurück zur Systemverwaltung</a></p>
</section>
