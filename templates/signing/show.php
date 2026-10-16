<?php
$v->title = 'Dokument unterzeichnen';
$st = $session['state'];
$doc = '/unterschreiben/' . $token . '/dokument';
$badge = static fn (string $s): string => match ($s) { 'signed' => '<span class="badge badge-ok">unterzeichnet</span>', 'declined' => '<span class="badge badge-bad">abgelehnt</span>', 'invited' => '<span class="badge badge-warn">ist an der Reihe</span>', default => '<span class="badge badge-neutral">wartet</span>' };
?>
<h1 class="h2"><i class="bx bx-pen" aria-hidden="true"></i> <?= e($session['title']) ?></h1>
<?php if ($session['message'] !== null && $session['message'] !== ''): ?><blockquote class="quote"><?= nl2br(e((string) $session['message'])) ?></blockquote><?php endif; ?>

<?php if ($st === 'signed'): ?>
  <div class="alert alert-success" role="status">Alle Unterschriften liegen vor. Das Dokument ist abgeschlossen und mit einem Signaturprotokoll versehen.</div>
  <p><a class="btn btn-primary" href="<?= e($doc) ?>?download=1"><i class="bx bx-download" aria-hidden="true"></i> Unterzeichnetes Dokument herunterladen</a></p>
<?php elseif ($st === 'declined'): ?>
  <div class="alert alert-warning" role="status">Die Unterzeichnung wurde abgelehnt. Es sind keine weiteren Schritte nötig.</div>
<?php elseif ($st === 'cancelled'): ?>
  <div class="alert alert-warning" role="status">Dieser Vorgang wurde von der absendenden Stelle zurückgezogen.</div>
<?php elseif ($st === 'expired'): ?>
  <div class="alert alert-warning" role="status">Die Frist für diese Unterzeichnung ist abgelaufen. Bitte bitten Sie um eine neue Einladung.</div>
<?php elseif ($signer['status'] === 'signed'): ?>
  <div class="alert alert-success" role="status">Sie haben dieses Dokument am <?= e(fdt($signer['signed_at'])) ?> unterzeichnet. Sobald alle Personen unterzeichnet haben, steht das fertige Dokument unter diesem Link bereit.</div>
<?php elseif ($canSign): ?>
  <p>Guten Tag <strong><?= e($signer['name']) ?></strong>. Bitte lesen Sie das Dokument und unterzeichnen Sie anschliessend in drei kurzen Schritten.</p>
  <ol class="todo">
    <li><span class="todo-n">1</span><div><strong>Dokument lesen.</strong> <a href="<?= e($doc) ?>" target="_blank" rel="noopener">PDF öffnen</a> · <a href="<?= e($doc) ?>?download=1">herunterladen</a><br>
      <span class="muted small">Prüfsumme (SHA-256): <code><?= e(substr((string) $session['pdf_sha256'], 0, 32)) ?>…</code></span></div></li>
    <li><span class="todo-n">2</span><div><strong>Code anfordern.</strong> Wir senden einen 6-stelligen Code an <?= e(preg_replace('/(?<=.).(?=[^@]*@)/u', '•', (string) $signer['email'])) ?>.
      <?= $ui->action('/unterschreiben/' . $token . '/code', $codeActive ? 'Neuen Code senden' : 'Code per E-Mail senden', 'bx-envelope', 'btn btn-secondary btn-sm') ?></div></li>
    <li><span class="todo-n">3</span><div><strong>Bestätigen und unterzeichnen.</strong></div></li>
  </ol>
  <form method="post" action="/unterschreiben/<?= e($token) ?>" class="stack" autocomplete="off"><?= $ui->csrf() ?>
    <?= $ui->field('code', 'Code aus der E-Mail', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 8, 'autocomplete' => 'one-time-code', 'help' => $codeActive ? 'Der Code wurde gesendet und ist 15 Minuten gültig.' : 'Fordern Sie zuerst oben einen Code an.']) ?>
    <?= $ui->field('name', 'Ihr vollständiger Name als Unterschrift', ['required' => true, 'maxlength' => 190, 'value' => $signer['name']]) ?>
    <?= $ui->field('consent', $consent, ['type' => 'checkbox', 'required' => true]) ?>
    <div><button class="btn btn-primary" type="submit"><i class="bx bx-check-circle" aria-hidden="true"></i> Verbindlich unterzeichnen</button></div>
  </form>
  <details class="details">
    <summary>Ich möchte nicht unterzeichnen</summary>
    <form method="post" action="/unterschreiben/<?= e($token) ?>/ablehnen" class="stack"><?= $ui->csrf() ?>
      <?= $ui->field('reason', 'Grund (optional)', ['maxlength' => 500]) ?>
      <div><button class="btn btn-secondary" type="submit" data-confirm="Unterzeichnung wirklich ablehnen? Der Vorgang wird damit für alle beendet.">Unterzeichnung ablehnen</button></div>
    </form>
  </details>
<?php endif; ?>

<?php if (count($signers) > 1): ?>
  <h2 class="h3">Beteiligte</h2>
  <ul class="checks"><?php foreach ($signers as $g): ?><li><?= $badge((string) $g['status']) ?> <?= e($g['name']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p class="muted small">Einfache elektronische Signatur der Plattform: Ihre Identität wird über den Zugriff auf diese E-Mail-Adresse bestätigt. Es handelt sich nicht um eine qualifizierte elektronische Signatur. Beim Unterzeichnen werden Zeitpunkt, IP-Adresse und Browser-Kennung als Beleg gespeichert und im Signaturprotokoll des Dokuments aufgeführt.</p>
