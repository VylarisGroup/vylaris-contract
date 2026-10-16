<?php $v->title = 'Aufgaben'; ?>
<section class="stack">
  <h1 class="h1">Geplante Aufgaben</h1>
  <div class="card">
    <p class="muted small">Der automatische Lauf erfolgt über den Plesk-Cron (docs/CRON.md). Hier können die Aufgaben zusätzlich von Hand gestartet werden.</p>
    <?= $ui->action('/system/cron', 'Jetzt ausführen', 'bx-play', 'btn btn-secondary btn-sm', 'Alle periodischen Aufgaben jetzt ausführen?') ?>
    <?php if ($jobs === []): ?><p class="muted">Noch kein Lauf registriert.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-sm"><thead><tr><th scope="col">Aufgabe</th><th scope="col">Letzter Lauf</th><th scope="col">Ergebnis</th></tr></thead><tbody>
    <?php foreach ($jobs as $j): ?><tr><td><?= e($j['job_name']) ?></td><td><?= e(fdt($j['last_finished_at'] ?? $j['last_started_at'])) ?></td><td><?= e((string) $j['last_status']) ?> – <?= e((string) $j['last_message']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <p><a href="/system">← Zurück zur Übersicht</a></p>
</section>
