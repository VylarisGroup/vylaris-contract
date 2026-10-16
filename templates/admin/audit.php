<?php $v->title = 'Protokoll'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Protokoll</h1>
  <form method="get" action="/verwaltung/protokoll" class="card filters"><div class="field grow"><label class="label" for="aktion">Aktion beginnt mit</label><input class="input" id="aktion" name="aktion" value="<?= e($action) ?>" placeholder="z. B. auth. oder contract."></div><div class="field"><button class="btn btn-primary" type="submit">Filtern</button></div></form>
  <div class="table-wrap"><table class="table table-sm"><thead><tr><th scope="col">Nr.</th><th scope="col">Zeit</th><th scope="col">Aktion</th><th scope="col">Ergebnis</th><th scope="col">Objekt</th><th scope="col">Person</th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td><?= (int) $ev['seq'] ?></td><td class="nowrap"><?= e(fdt($ev['occurred_at'])) ?></td><td><?= e($ev['action']) ?></td>
    <td><span class="badge <?= $ev['outcome'] === 'success' ? 'badge-ok' : ($ev['outcome'] === 'denied' ? 'badge-warn' : 'badge-bad') ?>"><?= e($ev['outcome']) ?></span></td>
    <td><?= e($ev['object_type']) ?> <?= $ev['object_id'] ? '#' . (int) $ev['object_id'] : '' ?></td><td><?= e($ev['actor'] ?? '–') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <nav class="pager" aria-label="Seiten"><?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="?seite=<?= $page - 1 ?>&amp;aktion=<?= e(rawurlencode($action)) ?>">Neuere</a><?php endif; ?>
  <?php if (count($events) === 50): ?><a class="btn btn-secondary btn-sm" href="?seite=<?= $page + 1 ?>&amp;aktion=<?= e(rawurlencode($action)) ?>">Ältere</a><?php endif; ?></nav>
  <p class="muted small">Das Protokoll ist hash-verkettet (manipulationserkennend). Es schützt nicht vor Personen mit Vollzugriff auf die Datenbank; der Kettenkopf sollte regelmässig extern gesichert werden.</p>
</section>
