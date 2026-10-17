<?php $v->title = 'Start'; $statuses = ['draft', 'review', 'approved', 'signature', 'active', 'expired']; ?>
<section class="stack">
  <h1 class="h1">Übersicht</h1>
  <?php if ((int) $data['total'] === 0): ?>
    <div class="card welcome">
      <h2 class="h2"><i class="bx bx-hand" aria-hidden="true"></i> Willkommen!</h2>
      <p>Hier ist noch nichts erfasst. So kommen Sie in wenigen Minuten zum ersten Ergebnis:</p>
      <ol class="todo">
        <li><span class="todo-n">1</span><div><strong>Mit Vertragspartnern verbinden</strong> – über den Mandanten-Code. <a href="/gegenparteien#verbinden">Gegenparteien</a></div></li>
        <li><span class="todo-n">2</span><div><strong>Ersten Vertrag erfassen</strong> – mit Laufzeit und Kündigungsfrist. <a href="/vertraege/neu">Neuer Vertrag</a></div></li>
        <li><span class="todo-n">3</span><div><strong>Anleitungen ansehen</strong> – Schritt für Schritt erklärt. <a href="/hilfe">Hilfe &amp; Anleitungen</a></div></li>
      </ol>
    </div>
  <?php endif; ?>
  <div class="stats">
    <?php foreach ($statuses as $s): ?>
      <a class="stat" href="/vertraege?status=<?= e($s) ?>"><span class="stat-n"><?= (int) ($data['by_status'][$s] ?? 0) ?></span><span class="stat-l"><?= e(fstatus($s)) ?></span></a>
    <?php endforeach; ?>
    <a class="stat" href="/vertraege"><span class="stat-n"><?= (int) $data['total'] ?></span><span class="stat-l">Alle sichtbaren</span></a>
  </div>
  <div class="grid-2">
    <div class="card">
      <h2 class="h3"><i class="bx bx-check-shield" aria-hidden="true"></i> Meine Freigaben</h2>
      <?php if ($data['approvals'] === []): ?><p class="muted">Nichts offen.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($data['approvals'] as $a): ?>
        <li><a href="/vertraege/<?= (int) $a['contract_id'] ?>"><?= e($a['contract_number']) ?> – <?= e($a['title']) ?></a> <span class="muted small">Schritt <?= (int) $a['step_no'] ?></span></li>
      <?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h2 class="h3"><i class="bx bx-task" aria-hidden="true"></i> Meine Aufgaben</h2>
      <?php if ($data['tasks'] === []): ?><p class="muted">Keine offenen Aufgaben.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($data['tasks'] as $t): ?>
        <li><a href="/vertraege/<?= (int) $t['contract_id'] ?>"><?= e($t['title']) ?></a> <span class="muted small"><?= e($t['contract_number']) ?> · fällig <?= e(fdate($t['due_date'])) ?></span></li>
      <?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h2 class="h3"><i class="bx bx-calendar-exclamation" aria-hidden="true"></i> Anstehende Fristen (90 Tage)</h2>
      <?php if ($data['deadlines'] === []): ?><p class="muted">Keine Fristen.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($data['deadlines'] as $d): ?>
        <li><strong><?= e(fdate($d['due_date'])) ?></strong> – <a href="/vertraege/<?= (int) $d['contract_id'] ?>"><?= e($d['label']) ?></a> <span class="muted small"><?= e($d['contract_number']) ?></span></li>
      <?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h2 class="h3"><i class="bx bx-time-five" aria-hidden="true"></i> Laufen bald aus</h2>
      <?php if ($data['expiring'] === []): ?><p class="muted">Keine auslaufenden Verträge.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($data['expiring'] as $c): ?>
        <li><a href="/vertraege/<?= (int) $c['id'] ?>"><?= e($c['contract_number']) ?> – <?= e($c['title']) ?></a> <span class="muted small">bis <?= e(fdate($c['end_date'])) ?></span></li>
      <?php endforeach; ?></ul>
    </div>
  </div>
  <?php if (!empty($an)): $sigL = ['prepared' => 'vorbereitet', 'submitted' => 'versendet', 'partially_signed' => 'teilweise unterzeichnet'];
    $maxC = max(1, ...array_map(static fn (array $c): int => $c['n'], $an['created'])); ?>
  <div class="row-between"><h2 class="h2">Auswertungen</h2>
    <?php if ($p->can('contract.export')): ?><a class="btn btn-secondary btn-sm" href="/uebersicht/export.csv"><i class="bx bx-download" aria-hidden="true"></i> Als CSV exportieren</a><?php endif; ?></div>
  <div class="stats">
    <a class="stat" href="/vertraege?status=active"><span class="stat-n"><?= (int) $an['expiry']['d30'] ?></span><span class="stat-l">Ablauf in 30 Tagen</span></a>
    <a class="stat" href="/vertraege?status=active"><span class="stat-n"><?= (int) $an['expiry']['d60'] ?></span><span class="stat-l">in 31–60 Tagen</span></a>
    <a class="stat" href="/vertraege?status=active"><span class="stat-n"><?= (int) $an['expiry']['d90'] ?></span><span class="stat-l">in 61–90 Tagen</span></a>
    <a class="stat" href="/vertraege?status=active"><span class="stat-n"><?= (int) $an['expiry']['overdue'] ?></span><span class="stat-l">Ende überschritten, noch aktiv</span></a>
  </div>
  <div class="grid-2">
    <div class="card">
      <h3 class="h3"><i class="bx bx-coin-stack" aria-hidden="true"></i> Vertragswerte (aktive Verträge)</h3>
      <?php if ($an['values'] === []): ?><p class="muted">Keine Verträge mit Wert und Währung.</p><?php else: ?>
      <table class="table"><thead><tr><th>Vertragsart</th><th class="num">Anzahl</th><th class="num">Summe</th></tr></thead><tbody>
        <?php foreach ($an['values'] as $x): ?><tr><td><?= e($x['type_name']) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= e(number_format((float) $x['total'], 2, '.', '’')) ?> <?= e($x['currency']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="muted small">Summen je Währung, nicht umgerechnet.</p><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="h3"><i class="bx bx-timer" aria-hidden="true"></i> Freigaben</h3>
      <p><?= $an['approval']['avg_days'] === null ? '<span class="muted">Noch keine entschiedenen Freigaben in den letzten 180 Tagen.</span>' : 'Ø Dauer bis zur Entscheidung: <strong>' . e(number_format((float) $an['approval']['avg_days'], 1, ',', '’')) . ' Tage</strong> <span class="muted small">(' . (int) $an['approval']['decided'] . ' in 180 Tagen)</span>' ?></p>
      <p>Offen: <strong><?= (int) $an['approval']['pending'] ?></strong><?= $an['approval']['oldest_pending_days'] !== null ? ' <span class="muted small">· ältestes seit ' . (int) $an['approval']['oldest_pending_days'] . ' Tagen</span>' : '' ?></p>
    </div>
    <div class="card">
      <h3 class="h3"><i class="bx bx-pen" aria-hidden="true"></i> Offene Signaturen (<?= (int) $an['signatures']['open'] ?>)</h3>
      <?php if ($an['signatures']['list'] === []): ?><p class="muted">Keine Signaturvorgänge offen.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($an['signatures']['list'] as $s): ?>
        <li><a href="/vertraege/<?= (int) $s['contract_id'] ?>"><?= e($s['contract_number']) ?> – <?= e($s['title']) ?></a> <span class="muted small"><?= e($sigL[$s['status']] ?? $s['status']) ?> · seit <?= (int) $s['age_days'] ?> Tagen</span></li>
      <?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h3 class="h3"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i> Neu erfasste Verträge (6 Monate)</h3>
      <table class="table"><caption class="sr-only">Neu erfasste Verträge pro Monat</caption><tbody>
        <?php foreach ($an['created'] as $c): ?><tr><th scope="row" class="small"><?= e($c['month']) ?></th><td><progress class="bar" max="<?= (int) $maxC ?>" value="<?= (int) $c['n'] ?>"></progress> <?= (int) $c['n'] ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
  <?php endif; ?>
</section>
