<?php $v->title = 'Aktualisierung'; ?>
<section class="stack">
  <h1 class="h1">Systemaktualisierung</h1>
  <div class="card">
    <p>Installierte Version: <strong><?= e($current) ?></strong> · Quelle: <code>github.com/<?= e($repo) ?></code> (neueste Release oder Hauptzweig »main«, fest hinterlegt)</p>
    <?= $ui->action('/system/aktualisierung/pruefen', 'Auf neue Version prüfen', 'bx-refresh', 'btn btn-secondary') ?>
  </div>

  <div class="card"><h2 class="h2">Voraussetzungen</h2>
    <ul><?php foreach ($checks as $c): ?><li><span class="badge <?= $c['status'] === 'ok' ? 'badge-ok' : 'badge-warn' ?>"><?= $c['status'] === 'ok' ? 'OK' : ($c['status'] === 'warn' ? 'Hinweis' : 'Problem') ?></span> <strong><?= e($c['label']) ?>:</strong> <?= e($c['detail']) ?></li><?php endforeach; ?></ul>
    <?= $ui->action('/system/aktualisierung/rechte', 'Rechte reparieren', 'bx-wrench', 'btn btn-secondary btn-sm') ?>
  </div>

  <?php if (is_array($latest)): ?>
  <div class="card"><h2 class="h2">Neueste Version: <?= e($latest['version']) ?> (<?= e($latest['tag']) ?>)</h2>
    <p class="muted small">Geprüft am <?= e((string) $latest['checked_at']) ?> UTC · veröffentlicht <?= e((string) $latest['published_at']) ?><?php if ($latest['url'] !== ''): ?> · <a href="<?= e($latest['url']) ?>" rel="noopener noreferrer" target="_blank">auf GitHub ansehen</a><?php endif; ?></p>
    <?php if ($latest['notes'] !== ''): ?><pre class="small" style="white-space:pre-wrap"><?= e($latest['notes']) ?></pre><?php endif; ?>
    <?php if (version_compare($latest['version'], $current, '>')): ?>
      <div class="alert alert-warning" role="note">Ablauf: Sicherung (Datenbank + Dokumente) → Wartungsmodus → Programmdateien ersetzen → Datenbank-Migrationen → Selbstprüfung der Seite → bei jedem Fehler automatischer Rückfall auf den alten Programmstand. <strong>Es erfolgt keine kryptographische Prüfung des Pakets</strong>; die SHA-256 wird nur protokolliert. Die Release muss von Ihnen als vertrauenswürdig eingestuft sein.</div>
      <?= $ui->form('/system/aktualisierung/installieren', $ui->field('sha256', 'Erwartete SHA-256 des Pakets (optional)', ['help' => 'Wenn angegeben, wird nur installiert, falls die Prüfsumme des geladenen Pakets übereinstimmt.']) . '<input type="hidden" name="version" value="' . e($latest['version']) . '"><button class="btn btn-primary" type="submit" data-confirm="Jetzt auf Version ' . e($latest['version']) . ' aktualisieren? Das System ist währenddessen kurz nicht erreichbar.">Auf ' . e($latest['version']) . ' aktualisieren</button>') ?>
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
