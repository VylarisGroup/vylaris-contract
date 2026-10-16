<?php $v->title = 'Gegenparteien'; ?>
<section class="stack">
  <div class="row-between"><h1 class="h1">Gegenparteien</h1><?php if ($p->can('contract.create')): ?><a class="btn btn-primary" href="/gegenparteien/neu"><i class="bx bx-plus" aria-hidden="true"></i> Neue Gegenpartei</a><?php endif; ?></div>
  <form method="get" action="/gegenparteien" class="card filters" role="search"><div class="field grow"><label class="label" for="q">Name</label><input class="input" id="q" name="q" type="search" value="<?= e($q) ?>"></div><div class="field"><button class="btn btn-primary" type="submit">Suchen</button></div></form>
  <?php if ($items === []): ?><div class="card"><p>Keine Einträge.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Art</th><th scope="col">Ort</th><th scope="col">Kontakt</th></tr></thead><tbody>
  <?php foreach ($items as $c): ?><tr><td><a href="/gegenparteien/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?= (int) ($c['sensitive_data'] ?? 0) === 1 ? ' <span class="badge badge-warn">sensibel</span>' : '' ?></td>
    <td><?= e(['company' => 'Unternehmen', 'association' => 'Verein', 'organization' => 'Organisation', 'person' => 'Privatperson'][$c['party_type']] ?? $c['party_type']) ?></td>
    <td><?= e(trim(($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? ''))) ?: '–' ?></td><td><?= e($c['contact_email'] ?? '–') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
