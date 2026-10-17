<?php $v->title = 'Geteilte Verträge'; ?>
<section class="stack">
  <p class="muted small"><a href="/gegenparteien">Gegenparteien</a></p>
  <h1 class="h1">Mit uns geteilte Verträge</h1>
  <p class="muted">Verträge verbundener Mandanten, in denen Ihr Mandant als Partei eingetragen ist. Sie sind nur lesbar und erscheinen erst ab der Freigabe. Beendet eine Seite die Verbindung, verschwinden sie sofort.</p>
  <?php if ($items === []): ?><div class="card"><p>Zurzeit wird nichts mit Ihnen geteilt.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Nummer</th><th scope="col">Titel</th><th scope="col">Von</th><th scope="col">Ihre Rolle</th><th scope="col">Status</th><th scope="col">Ende</th></tr></thead><tbody>
  <?php foreach ($items as $c): ?><tr><td><a href="/geteilt/<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?></a></td><td><?= e($c['title']) ?></td><td><?= e($c['owner_name']) ?></td><td><?= e($c['role_label']) ?></td>
    <td><span class="badge badge-neutral"><?= e(['approved' => 'Freigegeben', 'signature' => 'In Unterzeichnung', 'active' => 'Aktiv', 'expired' => 'Abgelaufen', 'archived' => 'Archiviert'][$c['status']] ?? $c['status']) ?></span></td>
    <td><?= $c['end_date'] ? e(fdate($c['end_date'])) : '–' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
