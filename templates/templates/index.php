<?php $v->title = 'Vorlagen'; ?>
<section class="stack">
  <div class="row-between"><h1 class="h1">Vorlagen</h1>
    <div class="row-gap"><a class="btn btn-secondary" href="/klauseln"><i class="bx bx-paragraph" aria-hidden="true"></i> Klauselbibliothek</a>
    <?php if ($p->can('template.manage')): ?><a class="btn btn-primary" href="/vorlagen/neu"><i class="bx bx-plus" aria-hidden="true"></i> Neue Vorlage</a><?php endif; ?></div></div>
  <p><a href="/vorlagen?archiv=<?= $archived ? '0' : '1' ?>"><?= $archived ? 'Aktive Vorlagen anzeigen' : 'Archivierte anzeigen' ?></a></p>
  <?php if ($templates === []): ?><div class="card"><p>Keine Vorlagen vorhanden.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Rechtsgebiet</th><th scope="col">Prüfstatus</th><th scope="col">Version</th></tr></thead><tbody>
  <?php foreach ($templates as $t): ?><tr><td><a href="/vorlagen/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></td><td><?= e($t['jurisdiction']) ?></td>
    <td><span class="badge <?= ($t['review_status'] ?? '') === 'approved' ? 'badge-ok' : 'badge-warn' ?>"><?= e(['approved' => 'fachlich freigegeben', 'in_review' => 'in Prüfung', 'unreviewed' => 'ungeprüft'][$t['review_status'] ?? ''] ?? ($t['review_status'] ?? '–')) ?></span></td>
    <td><?= isset($t['version_no']) ? 'v' . (int) $t['version_no'] : '–' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
