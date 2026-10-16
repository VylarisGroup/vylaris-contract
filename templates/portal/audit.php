<?php $v->title = 'Prüfung'; $c = $view['contract']; $ver = $view['version']; $id = (int) $c['id']; ?>
<section class="stack">
  <p class="muted small"><a href="/vertraege">Verträge</a> / Prüfansicht</p>
  <h1 class="h1"><?= e($c['contract_number']) ?> – <?= e($c['title']) ?></h1>
  <p><span class="badge <?= e(status_class((string) $c['status'])) ?>"><?= e(fstatus((string) $c['status'])) ?></span> <a class="btn btn-secondary btn-sm" href="/portal/pruefung/<?= $id ?>/bericht"><i class="bx bxs-file-pdf" aria-hidden="true"></i> Prüfbericht (PDF)</a></p>
  <div class="card"><h2 class="h2">Freigegebene Fassung<?= $ver ? ' (v' . (int) $ver['version_no'] . ')' : '' ?></h2>
    <?php if ($ver): ?><p class="small">SHA-256: <code class="hash"><?= e($ver['content_hash']) ?></code> · gesperrt <?= e(fdt($ver['locked_at'])) ?></p><div class="doc-preview"><?= \App\Core\HtmlSanitizer::clean((string) $ver['body_html']) ?></div>
    <?php else: ?><p class="muted">Es liegt keine freigegebene Fassung vor.</p><?php endif; ?></div>
  <div class="card"><h2 class="h3">Versionen</h2><ul class="list-plain"><?php foreach ($view['versions'] as $x): ?><li>v<?= (int) $x['version_no'] ?> · <code class="hash"><?= e(substr((string) $x['content_hash'], 0, 16)) ?>…</code> · <?= e(fdt($x['locked_at'])) ?></li><?php endforeach; ?></ul></div>
  <div class="card"><h2 class="h3">Verlauf</h2>
    <?php if ($history === []): ?><p class="muted">Kein Verlauf verfügbar.</p><?php endif; ?>
    <ul class="list-plain"><?php foreach ($history as $h): ?><li class="small"><?= e(fdt($h['occurred_at'] ?? $h['created_at'] ?? null)) ?> – <?= e($h['action'] ?? ($h['summary'] ?? '')) ?> <?= e($h['outcome'] ?? '') ?></li><?php endforeach; ?></ul></div>
  <div class="card"><h2 class="h3">Nachweise</h2>
    <h3 class="small">Freigaben</h3><ul class="list-plain"><?php foreach ($evidence['approvals'] as $a): ?><li class="small">Schritt <?= (int) $a['step_no'] ?>: <?= e($a['status']) ?> · <?= e(fdt($a['decided_at'] ?? null)) ?></li><?php endforeach; ?></ul>
    <h3 class="small">Signaturen</h3><ul class="list-plain"><?php foreach ($evidence['signatures'] as $s): ?><li class="small"><?= e($s['provider'] ?? '') ?><?= ($s['mode'] ?? '') === 'sandbox' ? ' (TESTMODUS, nicht rechtsgültig)' : '' ?> · Stufe <?= e($s['requested_level'] ?? '') ?> · <?= e($s['status'] ?? '') ?></li><?php endforeach; ?></ul>
    <?php $ch = $evidence['chain'] ?? []; ?><p class="small muted">Integrität des Protokolls: <?= !empty($ch['ok']) ? 'Kette konsistent' : 'nicht bestätigt' ?>. Hinweis: Das Protokoll ist manipulationserkennend, nicht manipulationssicher gegenüber Datenbankadministratoren.</p></div>
  <div class="card"><h2 class="h3">Anhänge</h2><ul class="list-plain"><?php foreach ($documents as $d): ?><li><a href="/dokumente/<?= (int) $d['id'] ?>"><?= e($d['original_filename']) ?></a></li><?php endforeach; ?></ul></div>
  <div class="card"><h2 class="h3">Prüfvermerke</h2>
    <?php foreach ($comments as $cm): ?><p class="small muted"><?= e(fdt($cm['created_at'])) ?></p><p><?= nl2br(e($cm['body'])) ?></p><?php endforeach; ?>
    <?= $ui->form('/portal/pruefung/' . $id . '/notiz', $ui->field('text', 'Neuer Vermerk (nur für Sie und den Bericht sichtbar)', ['type' => 'textarea', 'rows' => 3, 'required' => true]) . '<button class="btn btn-secondary" type="submit">Speichern</button>') ?></div>
</section>
