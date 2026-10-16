<?php
$v->title = 'Verträge';
$sorts = ['updated' => 'Zuletzt geändert', 'end' => 'Vertragsende', 'title' => 'Titel', 'number' => 'Nummer', 'value' => 'Wert'];
$statusOpts = ['' => 'Alle Status'] + \App\Web\Format::STATUS;
?>
<section class="stack">
  <div class="row-between">
    <h1 class="h1">Verträge</h1>
    <div class="row-gap">
      <?php if ($p->can('contract.export')): ?><a class="btn btn-secondary" href="/vertraege/export.csv?<?= e($query) ?>"><i class="bx bx-download" aria-hidden="true"></i> CSV-Export</a><?php endif; ?>
      <?php if ($p->can('contract.create')): ?><a class="btn btn-primary" href="/vertraege/neu"><i class="bx bx-plus" aria-hidden="true"></i> Neuer Vertrag</a><?php endif; ?>
    </div>
  </div>
  <form method="get" action="/vertraege" class="card filters" role="search">
    <div class="field grow"><label class="label" for="q">Suche (Titel, Nummer, Text, Gegenpartei, Tag)</label><input class="input" id="q" name="q" type="search" value="<?= e($f['q'] ?? '') ?>" maxlength="200"></div>
    <div class="field"><label class="label" for="status">Status</label><select class="input" id="status" name="status"><?php foreach ($statusOpts as $k => $t): ?><option value="<?= e($k) ?>"<?= (string) ($f['status'] ?? '') === (string) $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="label" for="ctype">Vertragsart</label><select class="input" id="ctype" name="contract_type_id"><option value="">Alle</option><?php foreach ($types as $id => $n): ?><option value="<?= (int) $id ?>"<?= (int) ($f['contract_type_id'] ?? 0) === $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="label" for="dept">Abteilung</label><select class="input" id="dept" name="department_id"><option value="">Alle</option><?php foreach ($departments as $id => $n): ?><option value="<?= (int) $id ?>"<?= (int) ($f['department_id'] ?? 0) === $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="label" for="end_to">Ende bis</label><input class="input" id="end_to" name="end_to" type="date" value="<?= e($f['end_to'] ?? '') ?>"></div>
    <div class="field"><label class="label" for="sort">Sortierung</label><select class="input" id="sort" name="sort"><?php foreach ($sorts as $k => $t): ?><option value="<?= e($k) ?>"<?= ($f['sort'] ?? 'updated') === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><button class="btn btn-primary" type="submit"><i class="bx bx-search" aria-hidden="true"></i> Suchen</button></div>
  </form>
  <p class="muted" aria-live="polite"><?= (int) $result['total'] ?> Treffer<?= $result['pages'] > 1 ? ' · Seite ' . (int) $result['page'] . ' von ' . (int) $result['pages'] : '' ?>. Es werden nur Verträge angezeigt, auf die Sie Zugriff haben.</p>
  <?php if ($result['rows'] === []): ?>
    <div class="card empty">
      <i class="bx bx-folder-open" aria-hidden="true"></i>
      <p><strong>Keine Verträge gefunden.</strong></p>
      <?php if (array_filter(array_diff_key($f ?? [], ['sort' => 1, 'page' => 1]), static fn ($x): bool => $x !== '' && $x !== null && $x !== 0 && $x !== '0')): ?>
        <p class="muted">Mit anderen Suchbegriffen oder weniger Filtern finden Sie vielleicht mehr. <a href="/vertraege">Filter zurücksetzen</a></p>
      <?php else: ?>
        <p class="muted">Es wurde noch kein Vertrag erfasst, oder Sie haben noch keinen Zugriff auf Verträge.</p>
        <?php if ($p->can('contract.create')): ?><a class="btn btn-primary" href="/vertraege/neu"><i class="bx bx-plus" aria-hidden="true"></i> Ersten Vertrag erfassen</a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">Nummer</th><th scope="col">Titel</th><th scope="col">Art</th><th scope="col">Status</th><th scope="col">Ende</th><th scope="col" class="num">Wert</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $c): ?>
      <tr>
        <td class="nowrap"><a href="/vertraege/<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?></a></td>
        <td><?= e($c['title']) ?></td>
        <td><?= e($c['type_name']) ?></td>
        <td><span class="badge <?= e(status_class((string) $c['status'])) ?>"><?= e(fstatus((string) $c['status'])) ?></span></td>
        <td class="nowrap"><?= e(fdate($c['end_date'])) ?></td>
        <td class="num nowrap"><?= e(fmoney($c['value_amount'], $c['value_currency'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($result['pages'] > 1): ?>
  <nav class="pager" aria-label="Seiten">
    <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
      <a class="btn btn-sm <?= $i === $result['page'] ? 'btn-primary' : 'btn-secondary' ?>" href="/vertraege?<?= e($query !== '' ? $query . '&' : '') ?>seite=<?= $i ?>"<?= $i === $result['page'] ? ' aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
