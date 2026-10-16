<?php $v->title = 'System'; ?>
<section class="stack">
  <h1 class="h1">Systemverwaltung</h1>
  <div class="grid-3 tiles">
    <a class="tile" href="/system/diagnose"><i class="bx bx-pulse" aria-hidden="true"></i><strong>Diagnose</strong>
      <span class="muted small"><?= (int) $diagCount['ok'] ?> in Ordnung<?= $diagCount['warn'] > 0 ? ', ' . (int) $diagCount['warn'] . ' Hinweise' : '' ?><?= $diagCount['fail'] > 0 ? ', <strong>' . (int) $diagCount['fail'] . ' mit Handlungsbedarf</strong>' : '' ?></span></a>
    <a class="tile" href="/system/mandanten"><i class="bx bx-buildings" aria-hidden="true"></i><strong>Mandanten</strong><span class="muted small"><?= (int) $activeTenants ?> aktiv von <?= (int) $tenantCount ?> · neuen Mandanten anlegen</span></a>
    <a class="tile" href="/system/aktualisierung"><i class="bx bx-cloud-download" aria-hidden="true"></i><strong>Aktualisierung</strong><span class="muted small">Version <?= e($version) ?><?= is_array($latest) && !empty($latest['newer']) ? ' · <strong>' . e($latest['version']) . ' verfügbar</strong>' : '' ?></span></a>
    <a class="tile" href="/system/sicherungen"><i class="bx bx-data" aria-hidden="true"></i><strong>Sicherungen</strong><span class="muted small">Erstellen, prüfen, herunterladen, Aufbewahrung</span></a>
    <a class="tile" href="/system/audit-kette"><i class="bx bx-link-alt" aria-hidden="true"></i><strong>Audit-Kette</strong><span class="muted small"><?= is_array($verify) ? 'Letzte Prüfung: ' . ($verify['ok'] ? 'konsistent' : '<strong>nicht konsistent</strong>') : 'Integrität des Protokolls prüfen' ?></span></a>
    <a class="tile" href="/system/aufgaben"><i class="bx bx-timer" aria-hidden="true"></i><strong>Aufgaben</strong><span class="muted small"><?= $jobs === [] ? 'Noch kein Lauf registriert' : count($jobs) . ' geplante Aufgaben' ?></span></a>
  </div>
  <div class="card"><h2 class="h2"><i class="bx bx-slider-alt" aria-hidden="true"></i> Einstellungen</h2>
    <div class="row-gap">
      <?php foreach ([['darstellung', 'Darstellung', 'bx-palette'], ['adresse', 'Adresse & Netzwerk', 'bx-globe'], ['uploads', 'Uploads', 'bx-upload'], ['email-server', 'E-Mail-Server', 'bx-envelope'], ['absender', 'Absender', 'bx-at'], ['signatur', 'Signatur', 'bx-pen'], ['skribble', 'Skribble', 'bx-edit']] as [$u, $l, $i]): ?>
        <a class="btn btn-secondary btn-sm" href="/system/einstellungen/<?= e($u) ?>"><i class="bx <?= e($i) ?>" aria-hidden="true"></i> <?= e($l) ?></a>
      <?php endforeach; ?>
    </div></div>
</section>
