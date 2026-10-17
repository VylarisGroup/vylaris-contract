<?php
$v->title = 'Gegenparteien';
$admin = $p->can('tenant.manage');
$state = ['connected' => ['verbunden', 'badge-ok'], 'ended' => ['Verbindung beendet', 'badge-neutral'], 'legacy' => ['Altbestand', 'badge-warn']];
?>
<section class="stack">
  <div class="row-between"><h1 class="h1">Gegenparteien</h1><?php if ($sharedCount > 0 || $ov['active'] !== []): ?><a class="btn btn-secondary" href="/geteilt"><i class="bx bx-share-alt" aria-hidden="true"></i> Mit uns geteilte Verträge<?= $sharedCount > 0 ? ' (' . (int) $sharedCount . ')' : '' ?></a><?php endif; ?></div>
  <p class="muted">Gegenparteien werden nicht von Hand angelegt, sondern über den <strong>Mandanten-Code</strong> verbunden: Sie geben den Code der Gegenseite ein, die Gegenseite bestätigt – danach erscheinen sich beide gegenseitig als Gegenpartei und können einander Verträge zeigen.</p>

  <div class="card stack" id="verbinden">
    <h2 class="h2"><i class="bx bx-link" aria-hidden="true"></i> Mit Mandant verbinden</h2>
    <?php if ($admin): ?>
      <div class="alert alert-info" role="note"><strong>Ihr Mandanten-Code:</strong> <code class="code-big"><?= e((string) $myCode) ?></code><br>
        <span class="small">Geben Sie diesen Code an Ihre Vertragspartner weiter. Mit dem Code kann nur eine Anfrage gestellt werden – ohne Ihre Bestätigung geschieht nichts.</span>
        <div class="mt"><?= $ui->action('/gegenparteien/code-neu', 'Neuen Code erzeugen', 'bx-refresh', 'btn btn-ghost btn-sm', 'Neuen Code erzeugen? Der alte Code gilt dann nicht mehr für neue Anfragen. Bestehende Verbindungen bleiben.') ?></div></div>
      <?= $ui->form('/gegenparteien/verbinden',
          '<div class="wz-step stack" data-title="Code der Gegenseite" data-intro="Tragen Sie den Mandanten-Code Ihres Vertragspartners ein (Format XXXX-XXXX-XXXX).">' . $ui->field('code', 'Mandanten-Code', ['required' => true, 'maxlength' => 20, 'autocomplete' => 'off', 'placeholder' => 'ABCD-EFGH-JKLM']) . '</div>'
          . '<div class="wz-step stack" data-title="Nachricht" data-intro="Optional: eine kurze Nachricht, damit die Gegenseite Ihre Anfrage zuordnen kann.">' . $ui->field('message', 'Nachricht (optional)', ['maxlength' => 255]) . '</div>'
          . '<div class="wz-actions"><button class="btn btn-primary" type="submit"><i class="bx bx-link" aria-hidden="true"></i> Anfrage senden</button></div>', false, 'stack', 'wizard-on') ?>
    <?php else: ?>
      <p class="muted">Verbindungen verwaltet die Mandantenadministration.</p>
    <?php endif; ?>

    <?php if ($ov['incoming'] !== []): ?>
      <h3 class="h3 mt">Eingehende Anfragen</h3>
      <ul class="list-plain"><?php foreach ($ov['incoming'] as $c): ?>
        <li class="row-between"><span><strong><?= e($c['other_name']) ?></strong> möchte sich verbinden<?= $c['message'] ? ' – «' . e($c['message']) . '»' : '' ?> <span class="muted small"><?= e(fdt($c['created_at'])) ?></span></span>
          <?php if ($admin): ?><span class="row-gap">
            <form method="post" action="/verbindungen/<?= (int) $c['id'] ?>/entscheiden" class="inline"><?= $ui->csrf() ?><input type="hidden" name="decision" value="accept"><button class="btn btn-primary btn-sm" type="submit" data-confirm="Verbindung bestätigen? <?= e($c['other_name']) ?> sieht danach freigegebene Verträge, in denen Ihr Mandant Partei ist (nur lesen).">Bestätigen</button></form>
            <form method="post" action="/verbindungen/<?= (int) $c['id'] ?>/entscheiden" class="inline"><?= $ui->csrf() ?><input type="hidden" name="decision" value="reject"><button class="btn btn-ghost btn-sm" type="submit">Ablehnen</button></form></span><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($ov['outgoing'] !== []): ?>
      <h3 class="h3 mt">Gesendete Anfragen</h3>
      <ul class="list-plain"><?php foreach ($ov['outgoing'] as $c): ?>
        <li class="row-between"><span><?= e($c['other_name']) ?> <span class="badge badge-warn">wartet auf Bestätigung</span></span>
          <?php if ($admin): ?><?= $ui->action('/verbindungen/' . (int) $c['id'] . '/zurueckziehen', 'Zurückziehen', 'bx-x', 'btn btn-ghost btn-sm') ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($ov['active'] !== []): ?>
      <h3 class="h3 mt">Aktive Verbindungen</h3>
      <ul class="list-plain"><?php foreach ($ov['active'] as $c): ?>
        <li class="row-between"><span><strong><?= e($c['other_name']) ?></strong> <span class="muted small">seit <?= e(fdt($c['decided_at'])) ?></span></span>
          <?php if ($admin): ?><?= $ui->action('/verbindungen/' . (int) $c['id'] . '/beenden', 'Beenden', 'bx-unlink', 'btn btn-ghost btn-sm', 'Verbindung beenden? Geteilte Verträge sind für beide Seiten sofort nicht mehr sichtbar.') ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <form method="get" action="/gegenparteien" class="card filters" role="search"><div class="field grow"><label class="label" for="q">Name</label><input class="input" id="q" name="q" type="search" value="<?= e($q) ?>"></div><div class="field"><button class="btn btn-primary" type="submit">Suchen</button></div></form>
  <?php if ($items === []): ?><div class="card"><p>Noch keine Gegenparteien. Verbinden Sie sich oben mit einem Mandanten.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Ort</th><th scope="col">Kontakt</th></tr></thead><tbody>
  <?php foreach ($items as $c): [$sl, $sc] = $state[$c['link_state']] ?? ['–', 'badge-neutral']; ?><tr><td><a href="/gegenparteien/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a><?= (int) ($c['sensitive_data'] ?? 0) === 1 ? ' <span class="badge badge-warn">sensibel</span>' : '' ?></td>
    <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></td>
    <td><?= e(trim(($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? ''))) ?: '–' ?></td><td><?= e($c['contact_email'] ?? '–') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
