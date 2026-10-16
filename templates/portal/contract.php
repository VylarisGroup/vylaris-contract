<?php $v->title = $c['contract_number'] . ' ' . $c['title']; $id = (int) $c['id']; ?>
<section class="stack">
  <div>
    <p class="muted small"><a href="/vertraege">Verträge</a> / <?= e($c['contract_number']) ?></p>
    <h1 class="h1"><?= e($c['title']) ?></h1>
    <p><span class="badge <?= e(status_class((string) $c['status'])) ?>"><?= e(fstatus((string) $c['status'])) ?></span> <span class="muted small">Freigegebene Fassung<?= isset($c['versions'][0]) ? ' (v' . (int) $c['versions'][0]['version_no'] . ')' : '' ?></span></p>
  </div>
  <div class="card">
    <dl class="dl">
      <dt>Beginn</dt><dd><?= e(fdate($c['start_date'])) ?></dd><dt>Ende</dt><dd><?= e(fdate($c['end_date'])) ?></dd>
      <dt>Wert</dt><dd><?= e(fmoney($c['value_amount'], $c['value_currency'])) ?></dd>
      <?php if (isset($c['versions'][0]['content_hash'])): ?><dt>Prüfsumme (SHA-256)</dt><dd><code class="hash"><?= e($c['versions'][0]['content_hash']) ?></code></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card"><h2 class="h2">Vertragstext</h2><?php if ($preview === ''): ?><p class="muted">Kein Text verfügbar.</p><?php else: ?><div class="doc-preview"><?= $preview ?></div><?php endif; ?>
    <p><a class="btn btn-secondary" href="/vertraege/<?= $id ?>/export/pdf"><i class="bx bxs-file-pdf" aria-hidden="true"></i> PDF</a></p></div>
  <div class="card"><h2 class="h3">Dokumente</h2>
    <?php if ($documents === []): ?><p class="muted">Keine Dokumente freigegeben.</p><?php endif; ?>
    <ul class="list-plain"><?php foreach ($documents as $d): ?><li><a href="/dokumente/<?= (int) $d['id'] ?>"><?= e($d['original_filename']) ?></a> <span class="muted small"><?= e(\App\Web\Format::bytes((int) $d['size_bytes'])) ?></span></li><?php endforeach; ?></ul></div>
  <div class="card"><h2 class="h3">Kommentare</h2>
    <?php foreach ($comments as $cm): ?><p class="small muted"><?= e($cm['author']) ?> · <?= e(fdt($cm['created_at'])) ?></p><p><?= nl2br(e($cm['body'])) ?></p><?php endforeach; ?>
    <?php if ($comments === []): ?><p class="muted">Keine Kommentare.</p><?php endif; ?>
    <?php if ($p->userType === 'external_party'): ?>
      <?= $ui->form('/vertraege/' . $id . '/kommentare', $ui->field('body', 'Kommentar an die Vertragspartei', ['type' => 'textarea', 'rows' => 3, 'required' => true]) . '<input type="hidden" name="visibility" value="shared"><button class="btn btn-secondary" type="submit">Senden</button>') ?>
    <?php endif; ?></div>
  <p class="muted small">Die Unterzeichnung erfolgt über den Link, den Sie vom Signaturanbieter per E-Mail erhalten.</p>
</section>
