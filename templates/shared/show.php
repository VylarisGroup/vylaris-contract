<?php $v->title = $c['title']; ?>
<section class="stack">
  <p class="muted small"><a href="/geteilt">Geteilte Verträge</a> / <?= e($c['contract_number']) ?></p>
  <h1 class="h1"><?= e($c['title']) ?></h1>
  <div class="alert alert-info" role="note"><i class="bx bx-lock-alt" aria-hidden="true"></i> Dieser Vertrag gehört <strong><?= e($c['owner_name']) ?></strong> und wird Ihnen nur zur Ansicht geteilt. Der Zugriff wird protokolliert.</div>
  <div class="grid-2">
    <div class="card"><h2 class="h3">Eckdaten</h2><dl class="dl">
      <dt>Status</dt><dd><?= e(['approved' => 'Freigegeben', 'signature' => 'In Unterzeichnung', 'active' => 'Aktiv', 'expired' => 'Abgelaufen', 'archived' => 'Archiviert'][$c['status']] ?? $c['status']) ?></dd>
      <dt>Beginn</dt><dd><?= $c['start_date'] ? e(fdate($c['start_date'])) : '–' ?></dd>
      <dt>Ende</dt><dd><?= $c['end_date'] ? e(fdate($c['end_date'])) : '–' ?></dd>
      <dt>Kündigungsfrist</dt><dd><?= $c['notice_period_days'] !== null ? (int) $c['notice_period_days'] . ' Tage' : '–' ?></dd>
      <dt>Wert</dt><dd><?= $c['value_amount'] !== null ? e(number_format((float) $c['value_amount'], 2, '.', "'") . ' ' . $c['value_currency']) : '–' ?></dd>
      <dt>Recht / Gerichtsstand</dt><dd><?= e(($c['governing_law'] ?: '–') . ' / ' . ($c['venue'] ?: '–')) ?></dd></dl></div>
    <div class="card"><h2 class="h3">Parteien</h2><ul class="list-plain"><?php foreach ($c['parties'] as $pt): ?><li><?= e($pt['name']) ?> <span class="muted small">(<?= e($pt['role_label']) ?><?= (int) $pt['must_sign'] === 1 ? ', unterzeichnet' : '' ?>)</span></li><?php endforeach; ?></ul></div>
  </div>
  <div class="card"><h2 class="h2">Vertragstext (freigegebene Version)</h2>
    <?php if ($c['body_html'] === ''): ?><p class="muted">Kein Text vorhanden.</p><?php else: ?><div class="doc-preview"><?= $c['body_html'] ?></div><?php endif; ?></div>
  <div class="card"><h2 class="h3"><i class="bx bx-paperclip" aria-hidden="true"></i> Dokumente</h2>
    <?php if ($c['documents'] === []): ?><p class="muted">Keine Dokumente freigegeben (unterzeichnete Fassungen erscheinen hier).</p><?php endif; ?>
    <ul class="list-plain"><?php foreach ($c['documents'] as $d): ?><li><a href="/geteilt/<?= (int) $c['id'] ?>/dokument/<?= (int) $d['id'] ?>"><?= e($d['original_filename']) ?></a> <span class="muted small"><?= e(\App\Web\Format::bytes((int) $d['size_bytes'])) ?> · <?= e(fdt($d['created_at'])) ?></span></li><?php endforeach; ?></ul></div>
</section>
