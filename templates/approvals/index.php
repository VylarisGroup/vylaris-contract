<?php $v->title = 'Freigaben'; ?>
<section class="stack">
  <h1 class="h1">Offene Freigaben</h1>
  <?php if ($items === []): ?><p class="muted">Aktuell sind keine Freigaben für Sie offen.</p><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <?php if ($items !== []): ?><thead><tr><th scope="col">Vertrag</th><th scope="col">Schritt</th><th scope="col">Angefragt</th><th scope="col"><span class="sr-only">Aktion</span></th></tr></thead><?php endif; ?>
    <tbody>
    <?php foreach ($items as $a): ?>
      <tr>
        <td><a href="/vertraege/<?= (int) $a['contract_id'] ?>"><?= e($a['contract_number'] ?? '') ?> – <?= e($a['title'] ?? '') ?></a></td>
        <td><?= (int) $a['step_no'] ?></td>
        <td><?= e(fdt($a['created_at'] ?? null)) ?></td>
        <td><a class="btn btn-primary btn-sm" href="/vertraege/<?= (int) $a['contract_id'] ?>#freigabe">Prüfen &amp; entscheiden</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
