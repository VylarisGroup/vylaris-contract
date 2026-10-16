<?php $v->title = 'Diagnose'; ?>
<section class="stack">
  <h1 class="h1">Diagnose</h1>
  <p class="muted">Zustand von PHP, Datenbank, Rechten, Schlüsseln und Integrationen.</p>
  <div class="card">
    <div class="table-wrap"><table class="table table-sm"><tbody>
    <?php foreach ($diag as $d): ?><tr><th scope="row"><?= e($d['label']) ?></th>
      <td><span class="badge <?= ['ok' => 'badge-ok', 'warn' => 'badge-warn', 'fail' => 'badge-bad'][$d['status']] ?>"><?= e(['ok' => 'in Ordnung', 'warn' => 'Hinweis', 'fail' => 'Handlungsbedarf'][$d['status']]) ?></span></td>
      <td><?= e($d['detail']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <p><a href="/system">← Zurück zur Übersicht</a></p>
</section>
