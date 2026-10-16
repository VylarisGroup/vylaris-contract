<?php $v->title = 'Zugängliche Verträge'; ?>
<section class="stack">
  <h1 class="h1">Zugängliche Verträge</h1>
  <form method="get" action="/vertraege" class="card filters" role="search">
    <div class="field grow"><label class="label" for="q">Suche</label><input class="input" id="q" name="q" type="search" value="<?= e($f['q'] ?? '') ?>" maxlength="200"></div>
    <div class="field"><button class="btn btn-primary" type="submit"><i class="bx bx-search" aria-hidden="true"></i> Suchen</button></div>
  </form>
  <?php if ($result['rows'] === []): ?><div class="card"><p>Keine Verträge gefunden.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">Nummer</th><th scope="col">Titel</th><th scope="col">Status</th><th scope="col">Ende</th><th scope="col"><span class="sr-only">Öffnen</span></th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $c): ?>
      <tr><td class="nowrap"><?= e($c['contract_number']) ?></td><td><?= e($c['title']) ?></td>
        <td><span class="badge <?= e(status_class((string) $c['status'])) ?>"><?= e(fstatus((string) $c['status'])) ?></span></td><td><?= e(fdate($c['end_date'])) ?></td>
        <td><a href="<?= $p->userType === 'auditor' ? '/portal/pruefung/' : '/vertraege/' ?><?= (int) $c['id'] ?>">Öffnen</a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
