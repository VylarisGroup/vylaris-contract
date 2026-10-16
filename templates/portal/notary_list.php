<?php $v->title = 'Notariat'; ?>
<section class="stack">
  <h1 class="h1">Notariatsfälle</h1>
  <?php if ($cases === []): ?><p class="muted">Keine Fälle bereitgestellt.</p><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Vertrag</th><th scope="col">Büro</th><th scope="col">Status</th><th scope="col">Bereitgestellt</th><th scope="col"><span class="sr-only">Öffnen</span></th></tr></thead><tbody>
  <?php foreach ($cases as $n): ?><tr><td><?= e($n['contract_number']) ?> – <?= e($n['title']) ?></td><td><?= e($n['notary_office']) ?></td><td><?= e($n['status']) ?></td><td><?= e(fdt($n['provided_at'])) ?></td><td><a href="/portal/notariat/<?= (int) $n['id'] ?>">Öffnen</a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
