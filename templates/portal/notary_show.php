<?php $v->title = 'Notariatsfall'; $id = (int) $case['id']; ?>
<section class="stack">
  <p class="muted small"><a href="/portal/notariat">Notariatsfälle</a></p>
  <h1 class="h1">Notariatsfall #<?= $id ?></h1>
  <div class="card"><dl class="dl"><dt>Status</dt><dd><?= e(['provided' => 'Bereitgestellt', 'in_progress' => 'In Bearbeitung', 'inquiry' => 'Rückfrage', 'certified' => 'Beglaubigt', 'rejected' => 'Abgelehnt', 'completed' => 'Abgeschlossen'][$case['status']] ?? $case['status']) ?></dd><dt>Büro</dt><dd><?= e($case['notary_office']) ?></dd><dt>Hinweise</dt><dd><?= nl2br(e((string) $case['remarks'])) ?></dd>
    <dt>Bereitgestelltes Dokument</dt><dd><?php if (!empty($case['document_id'])): ?><a href="/dokumente/<?= (int) $case['document_id'] ?>">Herunterladen (PDF)</a><?php else: ?>–<?php endif; ?>
    <?php if (!empty($case['provided_pdf_sha256'])): ?><br><code class="hash"><?= e($case['provided_pdf_sha256']) ?></code><?php endif; ?></dd></dl>
    <p><a class="btn btn-secondary" href="/vertraege/<?= (int) $case['contract_id'] ?>">Vertrag ansehen</a></p></div>
  <div class="card"><h2 class="h3">Status melden</h2>
    <?= $ui->form('/portal/notariat/' . $id . '/status', $ui->field('status', 'Neuer Status', ['type' => 'select', 'options' => ['in_progress' => 'In Bearbeitung', 'inquiry' => 'Rückfrage an Auftraggeber', 'certified' => 'Beglaubigt / beurkundet (Rückläufer hochladen!)', 'rejected' => 'Abgelehnt']]) . $ui->field('remark', 'Bemerkung', ['type' => 'textarea', 'rows' => 2]) . '<button class="btn btn-primary" type="submit">Melden</button>') ?></div>
  <div class="card"><h2 class="h3">Rückgabedokument hochladen</h2>
    <?php foreach ($case['returns'] as $r): ?><p><a href="/dokumente/<?= (int) $r['id'] ?>"><?= e($r['original_filename']) ?></a> <span class="muted small"><?= e(fdt($r['created_at'])) ?></span></p><?php endforeach; ?>
    <?= $ui->form('/portal/notariat/' . $id . '/rueckgabe', $ui->field('file', 'Datei (PDF)', ['type' => 'file', 'required' => true, 'accept' => '.pdf,.docx,.png,.jpg']) . '<button class="btn btn-secondary" type="submit"><i class="bx bx-upload" aria-hidden="true"></i> Hochladen</button>', true) ?></div>
</section>
