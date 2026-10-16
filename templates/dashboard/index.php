<?php $v->title = 'Start'; $statuses = ['draft', 'review', 'approved', 'signature', 'active', 'expired']; ?>
<section class="stack">
  <h1 class="h1">Übersicht</h1>
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
</section>
