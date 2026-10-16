<?php $v->title = 'System'; ?>
<section class="stack">
  <h1 class="h1">Systemverwaltung</h1>

  <div class="card"><h2 class="h2"><i class="bx bx-pulse" aria-hidden="true"></i> Diagnose</h2>
    <div class="table-wrap"><table class="table table-sm"><tbody>
    <?php foreach ($diag as $d): ?><tr><th scope="row"><?= e($d['label']) ?></th>
      <td><span class="badge <?= ['ok' => 'badge-ok', 'warn' => 'badge-warn', 'fail' => 'badge-bad'][$d['status']] ?>"><?= e(['ok' => 'in Ordnung', 'warn' => 'Hinweis', 'fail' => 'Handlungsbedarf'][$d['status']]) ?></span></td>
      <td><?= e($d['detail']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

  <div class="card"><h2 class="h2"><i class="bx bx-cloud-download" aria-hidden="true"></i> Aktualisierung</h2>
    <p class="muted small">Neue Versionen aus dem GitHub-Repository einspielen.</p>
    <a class="btn btn-secondary" href="/system/aktualisierung"><i class="bx bx-refresh" aria-hidden="true"></i> Aktualisierung öffnen</a></div>

  <div class="card"><h2 class="h2"><i class="bx bx-slider-alt" aria-hidden="true"></i> Einstellungen &amp; Sicherungen</h2>
    <p class="muted small">E-Mail, Signaturanbieter, Allgemeines und Sicherungen werden hier gepflegt.</p>
    <a class="btn btn-secondary" href="/system/einstellungen"><i class="bx bx-cog" aria-hidden="true"></i> Einstellungen</a>
    <a class="btn btn-secondary" href="/system/sicherungen"><i class="bx bx-data" aria-hidden="true"></i> Sicherungen</a></div>

  <div class="card"><h2 class="h2"><i class="bx bx-link-alt" aria-hidden="true"></i> Audit-Kette</h2>
    <p class="muted small">Prüft die Hash-Verkettung aller Protokolleinträge und den letzten Anker. Erkennt nachträgliche Änderungen, nicht jedoch eine vollständige Neuberechnung durch jemanden mit Datenbank-Vollzugriff.</p>
    <?php if (is_array($verify)): ?><p>Letzte Prüfung (<?= e($verify['at']) ?> UTC): <strong><?= $verify['ok'] ? 'konsistent (' . (int) $verify['checked'] . ' Einträge)' : 'NICHT KONSISTENT – ' . e((string) $verify['reason']) ?></strong></p><?php endif; ?>
    <?= $ui->action('/system/pruefen', 'Kette jetzt prüfen', 'bx-check-shield', 'btn btn-secondary') ?></div>

  <div class="card"><h2 class="h2"><i class="bx bx-timer" aria-hidden="true"></i> Geplante Aufgaben (Cron)</h2>
    <p class="muted small">Der automatische Lauf erfolgt über den Plesk-Cron (docs/CRON.md). Hier können die Aufgaben zusätzlich von Hand gestartet werden.</p>
    <?= $ui->action('/system/cron', 'Jetzt ausführen', 'bx-play', 'btn btn-secondary btn-sm', 'Alle periodischen Aufgaben jetzt ausführen?') ?>
    <?php if ($jobs === []): ?><p class="muted">Noch kein Lauf registriert.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-sm"><thead><tr><th scope="col">Aufgabe</th><th scope="col">Letzter Lauf</th><th scope="col">Ergebnis</th></tr></thead><tbody>
    <?php foreach ($jobs as $j): ?><tr><td><?= e($j['job_name']) ?></td><td><?= e(fdt($j['last_finished_at'] ?? $j['last_started_at'])) ?></td><td><?= e((string) $j['last_status']) ?> – <?= e((string) $j['last_message']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

  <div class="card"><h2 class="h2"><i class="bx bx-buildings" aria-hidden="true"></i> Mandanten</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Kürzel</th><th scope="col">Mitglieder</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Aktion</span></th></tr></thead><tbody>
    <?php foreach ($tenants as $t): ?><tr><td><?= e($t['name']) ?></td><td><?= e($t['slug']) ?></td><td><?= (int) $t['members'] ?></td>
      <td><span class="badge <?= $t['status'] === 'active' ? 'badge-ok' : 'badge-bad' ?>"><?= $t['status'] === 'active' ? 'aktiv' : 'gesperrt' ?></span></td>
      <td><?= $t['status'] === 'active'
        ? $ui->action('/system/mandanten/' . (int) $t['id'] . '/status', 'Sperren', 'bx-block', 'btn btn-ghost btn-sm', 'Mandant sperren? Alle Benutzer verlieren den Zugriff.', ['status' => 'suspended'])
        : $ui->action('/system/mandanten/' . (int) $t['id'] . '/status', 'Aktivieren', 'bx-check', 'btn btn-ghost btn-sm', null, ['status' => 'active']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3 class="h3 mt">Neuer Mandant</h3>
    <form method="post" action="/system/mandanten" class="stack"><?= $ui->csrf() ?>
      <div class="grid-2">
        <?= $ui->field('name', 'Name', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('slug', 'Kürzel (a–z, 0–9, -)', ['required' => true, 'maxlength' => 61, 'pattern' => '[a-z0-9][a-z0-9-]+']) ?>
        <?= $ui->field('admin_name', 'Erste Administration – Name', ['required' => true, 'maxlength' => 190]) ?>
        <?= $ui->field('admin_email', 'Erste Administration – E-Mail', ['type' => 'email', 'required' => true, 'maxlength' => 190]) ?>
      </div>
      <div><button class="btn btn-primary" type="submit">Mandant anlegen &amp; einladen</button></div></form></div>
</section>
