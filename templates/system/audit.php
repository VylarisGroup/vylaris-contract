<?php $v->title = 'Audit-Kette'; ?>
<section class="stack">
  <h1 class="h1">Audit-Kette</h1>
  <div class="card">
    <p class="muted small">Prüft die Hash-Verkettung aller Protokolleinträge und den letzten Anker. Erkennt nachträgliche Änderungen, nicht jedoch eine vollständige Neuberechnung durch jemanden mit Datenbank-Vollzugriff.</p>
    <?php if (is_array($verify)): ?><p>Letzte Prüfung (<?= e($verify['at']) ?> UTC): <strong><?= $verify['ok'] ? 'konsistent (' . (int) $verify['checked'] . ' Einträge)' : 'NICHT KONSISTENT – ' . e((string) $verify['reason']) ?></strong></p><?php endif; ?>
    <?= $ui->action('/system/pruefen', 'Kette jetzt prüfen', 'bx-check-shield', 'btn btn-secondary') ?></div>
  <p><a href="/system">← Zurück zur Übersicht</a></p>
</section>
