<?php
$v->title = $c['contract_number'] . ' ' . $c['title'];
$id = (int) $c['id'];
$status = (string) $c['status'];
$myPending = array_values(array_filter($approvals, static fn (array $a): bool => (int) $a['approver_user_id'] === $p->userId && $a['status'] === 'pending'));
$openSig = array_values(array_filter($signatures, static fn (array $s): bool => in_array($s['status'], ['prepared', 'submitted', 'partially_signed'], true)));
$levelOpts = array_combine($levels, $levels);
$memberOpts = $members;
$latestVersion = $c['versions'][0] ?? null;
?>
<section class="stack">
  <div class="row-between">
    <div>
      <p class="muted small"><a href="/vertraege">Verträge</a> / <?= e($c['contract_number']) ?></p>
      <h1 class="h1"><?= e($c['title']) ?></h1>
      <p><span class="badge <?= e(status_class($status)) ?>"><?= e(fstatus($status)) ?></span>
        <?php if (!empty($c['parent_contract_id'])): ?><a class="badge badge-neutral" href="/vertraege/<?= (int) $c['parent_contract_id'] ?>"><?= $c['relation_type'] === 'amendment' ? 'Nachtrag' : 'Änderungsantrag' ?> zu Vertrag</a><?php endif; ?>
        <?php foreach ((array) ($c['tags'] ?? []) as $t): ?><span class="badge badge-neutral">#<?= e($t) ?></span><?php endforeach; ?>
        <?php if ($holds !== []): ?><span class="badge badge-bad"><i class="bx bx-lock-alt" aria-hidden="true"></i> Rechtliche Sperre</span><?php endif; ?></p>
    </div>
    <div class="row-gap">
      <?php if ($can['edit'] && $status === 'draft'): ?><a class="btn btn-primary" href="/vertraege/<?= $id ?>/bearbeiten"><i class="bx bx-edit" aria-hidden="true"></i> Bearbeiten</a><?php endif; ?>
      <?php if ($can['export']): ?>
        <a class="btn btn-secondary" href="/vertraege/<?= $id ?>/export/pdf"><i class="bx bxs-file-pdf" aria-hidden="true"></i> PDF</a>
        <a class="btn btn-secondary" href="/vertraege/<?= $id ?>/export/docx"><i class="bx bxs-file-doc" aria-hidden="true"></i> DOCX</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <h2 class="h3">Eckdaten</h2>
      <dl class="dl">
        <dt>Beginn</dt><dd><?= e(fdate($c['start_date'])) ?></dd>
        <dt>Ende</dt><dd><?= e(fdate($c['end_date'])) ?></dd>
        <dt>Kündigungsfrist</dt><dd><?= $c['notice_period_days'] !== null ? (int) $c['notice_period_days'] . ' Tage' : '–' ?></dd>
        <dt>Verlängerung</dt><dd><?= (int) $c['auto_renew'] === 1 ? 'automatisch' . ($c['renewal_period_days'] ? ' um ' . (int) $c['renewal_period_days'] . ' Tage' : '') : 'keine' ?><?= $c['renewal_terms'] ? ' – ' . e($c['renewal_terms']) : '' ?></dd>
        <dt>Wert</dt><dd><?= e(fmoney($c['value_amount'], $c['value_currency'])) ?></dd>
        <dt>Zahlungsbedingungen</dt><dd><?= e($c['payment_terms'] ?: '–') ?></dd>
        <dt>Recht / Gerichtsstand</dt><dd><?= e($c['governing_law'] ?: '–') ?> / <?= e($c['venue'] ?: '–') ?></dd>
      </dl>
      <?php if (!empty($c['description'])): ?><p class="muted"><?= nl2br(e($c['description'])) ?></p><?php endif; ?>
    </div>
    <div class="card">
      <h2 class="h3">Parteien</h2>
      <?php if ($c['parties'] === []): ?><p class="muted">Keine Parteien erfasst.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($c['parties'] as $pt): ?>
        <li><strong><?= e($pt['name']) ?></strong> <span class="muted small">(<?= e($pt['role_label']) ?><?= (int) $pt['must_sign'] === 1 ? ', Unterzeichner' : '' ?>)</span></li>
      <?php endforeach; ?></ul>
      <h2 class="h3 mt">Versionen</h2>
      <div class="table-wrap"><table class="table table-sm">
        <thead><tr><th scope="col">Nr.</th><th scope="col">Stand</th><th scope="col">SHA-256</th><th scope="col"><span class="sr-only">PDF</span></th></tr></thead>
        <tbody><?php foreach ($c['versions'] as $ver): ?>
          <tr><td>v<?= (int) $ver['version_no'] ?></td>
            <td><?= $ver['state'] === 'locked' ? '<span class="badge badge-info">gesperrt</span>' : '<span class="badge badge-neutral">Entwurf</span>' ?> <span class="small muted"><?= e(fdt($ver['locked_at'] ?? $ver['created_at'])) ?></span><?= $ver['change_note'] ? '<br><span class="small">' . e($ver['change_note']) . '</span>' : '' ?></td>
            <td><code class="hash" title="<?= e($ver['content_hash'] ?? '') ?>"><?= e(substr((string) ($ver['content_hash'] ?? ''), 0, 12)) ?><?= $ver['content_hash'] ? '…' : '–' ?></code></td>
            <td><?php if ($can['export']): ?><a href="/vertraege/<?= $id ?>/export/pdf?version=<?= (int) $ver['id'] ?>" aria-label="Version <?= (int) $ver['version_no'] ?> als PDF">PDF</a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  </div>

  <div class="card" id="text">
    <h2 class="h2">Vertragstext <span class="muted small">(aktuelle Version<?= $latestVersion ? ' v' . (int) $latestVersion['version_no'] : '' ?>)</span></h2>
    <?php if ($preview === ''): ?><p class="muted">Noch kein Text. <?php if ($can['edit'] && $status === 'draft'): ?><a href="/vertraege/<?= $id ?>/bearbeiten">Text erfassen</a> oder ein Dokument importieren.<?php endif; ?></p>
    <?php else: ?><div class="doc-preview"><?= $preview /* bereits bereinigtes HTML (HtmlSanitizer) */ ?></div><?php endif; ?>
    <?php if ($can['edit'] && $status === 'draft'): ?>
      <hr>
      <h3 class="h3">Dokument importieren</h3>
      <?= $ui->form('/vertraege/' . $id . '/import', $ui->field('file', 'Word (.docx) oder PDF', ['type' => 'file', 'accept' => '.docx,.pdf', 'required' => true, 'help' => 'DOCX: Text wird übernommen (vereinfachte Formatierung). PDF: Die Originaldatei wird unverändert abgelegt, ihr Text wird nicht ausgelesen (kein OCR).'])
        . '<button class="btn btn-secondary" type="submit"><i class="bx bx-upload" aria-hidden="true"></i> Importieren</button>', true) ?>
    <?php endif; ?>
  </div>

  <div class="card" id="freigabe">
    <h2 class="h2"><i class="bx bx-check-shield" aria-hidden="true"></i> Freigabe</h2>
    <?php if ($approvals === []): ?><p class="muted">Noch keine Freigabe angefordert.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-sm">
      <thead><tr><th scope="col">Version</th><th scope="col">Schritt</th><th scope="col">Person</th><th scope="col">Status</th><th scope="col">Entscheid</th></tr></thead>
      <tbody><?php foreach ($approvals as $a): ?>
        <tr><td>v<?= (int) $a['version_no'] ?></td><td><?= (int) $a['step_no'] ?></td><td><?= e($a['approver_name']) ?></td>
          <td><?= e(['pending' => 'offen', 'approved' => 'genehmigt', 'rejected' => 'abgelehnt', 'invalidated' => 'ungültig (neue Version)'][$a['status']] ?? $a['status']) ?></td>
          <td><?= e(fdt($a['decided_at'])) ?><?= $a['decision_comment'] ? ' – ' . e($a['decision_comment']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>

    <?php if ($myPending !== [] && $status === 'review'): $ap = $myPending[0]; ?>
      <h3 class="h3 mt">Ihr Entscheid</h3>
      <p class="muted small">Sie entscheiden über die unveränderliche Version, die oben angezeigt wird. Die Entscheidung ist an ihre Prüfsumme gebunden.</p>
      <form method="post" action="/freigaben/<?= (int) $ap['id'] ?>/entscheiden" class="stack">
        <?= $ui->csrf() ?><input type="hidden" name="contract_id" value="<?= $id ?>">
        <?= $ui->field('comment', 'Kommentar (bei Ablehnung Pflicht)', ['type' => 'textarea', 'rows' => 2]) ?>
        <div class="row-gap">
          <button class="btn btn-primary" type="submit" name="decision" value="approve" data-confirm="Diese Version verbindlich freigeben?"><i class="bx bx-check" aria-hidden="true"></i> Freigeben</button>
          <button class="btn btn-danger" type="submit" name="decision" value="reject"><i class="bx bx-x" aria-hidden="true"></i> Ablehnen</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($can['request_approval'] && in_array($status, ['draft', 'rejected', 'withdrawn'], true) && $latestVersion): ?>
      <h3 class="h3 mt">Freigabe anfordern</h3>
      <p class="muted small">Mit der Anforderung wird der aktuelle Text <strong>gesperrt</strong>. Wählen Sie je Stufe die freigebenden Personen (Stufen laufen nacheinander; innerhalb einer Stufe müssen alle zustimmen). Vier-Augen-Prinzip: Sie können Ihren eigenen Vertrag nicht selbst freigeben.</p>
      <form method="post" action="/vertraege/<?= $id ?>/freigabe" class="stack">
        <?= $ui->csrf() ?>
        <div class="grid-3">
          <?php foreach ([1, 2, 3] as $n): ?>
            <div class="field"><label class="label" for="step<?= $n ?>">Stufe <?= $n ?><?= $n > 1 ? ' (optional)' : '' ?></label>
              <select class="input" id="step<?= $n ?>" name="step<?= $n ?>[]" multiple size="5"<?= $n === 1 ? ' required' : '' ?>>
                <?php foreach ($approverOpts as $uid => $label): ?><option value="<?= (int) $uid ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select></div>
          <?php endforeach; ?>
        </div>
        <div><button class="btn btn-primary" type="submit" data-confirm="Version sperren und zur Freigabe senden?"><i class="bx bx-send" aria-hidden="true"></i> Zur Freigabe senden</button></div>
      </form>
    <?php endif; ?>
    <?php if ($status === 'review' && $can['request_approval']): ?>
      <?= $ui->form('/vertraege/' . $id . '/freigabe/zurueckziehen', $ui->field('reason', 'Grund', ['maxlength' => 255]) . '<button class="btn btn-secondary" type="submit" data-confirm="Freigabeantrag zurückziehen?">Antrag zurückziehen</button>', false, 'mt') ?>
    <?php endif; ?>
  </div>

  <div class="card" id="signatur">
    <h2 class="h2"><i class="bx bx-pen" aria-hidden="true"></i> Elektronische Unterzeichnung</h2>
    <?php if ($providerMode === 'sandbox'): ?>
      <div class="alert alert-warning" role="note"><strong>TESTMODUS</strong> (Anbieter: <?= e($providerName) ?>) – es entstehen <strong>keine rechtsgültigen Signaturen</strong> und der Vertrag wird dadurch nicht aktiviert.</div>
    <?php elseif ($providerName === 'internal'): ?>
      <p class="muted small"><i class="bx bx-info-circle" aria-hidden="true"></i> <strong>Eigene Signatur der Plattform (SES):</strong> Die Unterzeichnenden erhalten einen persönlichen Link per E-Mail, bestätigen mit einem Einmalcode und unterzeichnen mit Namen und Einwilligung. Am Ende erhält das PDF ein Signaturprotokoll und ein Siegel der Plattform. Das ist eine <em>einfache</em> elektronische Signatur, <strong>keine</strong> qualifizierte (QES) – ob sie für diesen Vertrag genügt, ist fachlich zu prüfen.</p>
    <?php else: ?>
      <p class="muted small">Anbieter: <?= e($providerName) ?> (Livemodus). Die Plattform meldet, was der Anbieter berichtet; sie prüft Signaturen nicht selbst. Qualifizierte Signaturen erfordern einen Anbieter mit entsprechender Zertifizierung und Identifikation der Unterzeichner.</p>
    <?php endif; ?>
    <?php foreach ($signatures as $s): $d = $s['display']; ?>
      <div class="sub-card">
        <div class="row-between"><strong>Vorgang #<?= (int) $s['id'] ?> · Stufe <?= e($s['requested_level']) ?></strong>
          <span class="badge <?= $s['status'] === 'signed' ? 'badge-info' : (in_array($s['status'], ['declined', 'failed', 'expired', 'cancelled'], true) ? 'badge-bad' : 'badge-warn') ?>"><?= e($d['label']) ?></span></div>
        <?php if ($s['mode'] === 'sandbox'): ?><p class="small"><strong>TESTMODUS</strong> – nicht rechtsgültig.</p><?php elseif ($d['notice']): ?><p class="small muted"><?= e($d['notice']) ?></p><?php endif; ?>
        <p class="small muted">Unterzeichnende: <?= e(implode(', ', array_map(static fn (array $x): string => $x['name'] . ' <' . $x['email'] . '>', $s['signers']))) ?> · Aktualisiert <?= e(fdt($s['updated_at'])) ?></p>
        <?php if (!empty($s['signer_status'])): ?>
          <ul class="checks"><?php foreach ($s['signer_status'] as $g): ?>
            <li><span class="badge <?= $g['status'] === 'signed' ? 'badge-ok' : ($g['status'] === 'declined' ? 'badge-bad' : ($g['status'] === 'invited' ? 'badge-warn' : 'badge-neutral')) ?>"><?= e(['signed' => 'unterzeichnet', 'declined' => 'abgelehnt', 'invited' => 'eingeladen', 'waiting' => 'wartet'][$g['status']] ?? $g['status']) ?></span>
              <?= e($g['name']) ?> <span class="muted small">&lt;<?= e($g['email']) ?>&gt;<?= $g['at'] ? ' · ' . e(fdt($g['at'])) : '' ?></span></li>
          <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($s['last_error']): ?><p class="small field-error"><?= e($s['last_error']) ?></p><?php endif; ?>
        <?php if (in_array($s['status'], ['submitted', 'partially_signed'], true) && $can['request_signature']): ?>
          <div class="row-gap">
            <?= $ui->action('/signaturen/' . (int) $s['id'] . '/aktualisieren', 'Status abfragen', 'bx-refresh', 'btn btn-secondary btn-sm', null, ['contract_id' => $id]) ?>
            <?php if (!empty($s['can_remind'])): ?><?= $ui->action('/signaturen/' . (int) $s['id'] . '/erinnern', 'Einladung erneut senden', 'bx-bell', 'btn btn-secondary btn-sm', 'Allen aktuell Eingeladenen einen neuen Link senden? Der bisherige Link wird ungültig.', ['contract_id' => $id]) ?><?php endif; ?>
            <?= $ui->action('/signaturen/' . (int) $s['id'] . '/abbrechen', 'Zurückziehen', 'bx-x', 'btn btn-ghost btn-sm', 'Signaturvorgang wirklich zurückziehen?', ['contract_id' => $id]) ?>
            <?php if ($s['mode'] === 'sandbox'): ?>
              <?= $ui->action('/signaturen/' . (int) $s['id'] . '/simulieren', 'Test: „unterzeichnet“ simulieren', 'bx-test-tube', 'btn btn-ghost btn-sm', null, ['contract_id' => $id, 'state' => 'signed']) ?>
              <?= $ui->action('/signaturen/' . (int) $s['id'] . '/simulieren', 'Test: „abgelehnt“ simulieren', 'bx-test-tube', 'btn btn-ghost btn-sm', null, ['contract_id' => $id, 'state' => 'declined']) ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($status === 'approved' && $can['request_signature'] && $openSig === []): ?>
      <h3 class="h3 mt">Unterzeichnung anfordern</h3>
      <form method="post" action="/vertraege/<?= $id ?>/signatur" class="stack">
        <?= $ui->csrf() ?>
        <?= $ui->field('level', 'Signaturstufe', ['type' => 'select', 'options' => $levelOpts, 'help' => 'SES = einfach, AES = fortgeschritten, QES = qualifiziert. Welche Stufe rechtlich erforderlich ist, hängt vom Vertrag ab – bitte fachlich klären.']) ?>
        <?php if ($providerName === 'internal'): ?>
          <?= $ui->field('order', 'Reihenfolge', ['type' => 'select', 'options' => ['parallel' => 'Gleichzeitig – alle erhalten sofort die Einladung', 'sequential' => 'Nacheinander – in der Reihenfolge der Liste'], 'help' => 'Bei «Nacheinander» wird die nächste Person erst eingeladen, wenn die vorherige unterzeichnet hat.']) ?>
        <?php endif; ?>
        <?php for ($i = 0; $i < 3; $i++): ?>
          <div class="grid-2"><?= $ui->field('signer_name[]', 'Name Unterzeichner/in ' . ($i + 1), ['maxlength' => 190]) ?><?= $ui->field('signer_email[]', 'E-Mail', ['type' => 'email', 'maxlength' => 190]) ?></div>
        <?php endfor; ?>
        <div><button class="btn btn-primary" type="submit" data-confirm="Unterzeichnung der freigegebenen Version anfordern?"><i class="bx bx-send" aria-hidden="true"></i> Anfordern</button></div>
      </form>
    <?php elseif ($status !== 'approved' && $signatures === []): ?>
      <p class="muted">Eine Unterzeichnung kann erst nach vollständiger Freigabe angefordert werden.</p>
    <?php endif; ?>
  </div>

  <div class="grid-2">
    <div class="card" id="dokumente">
      <h2 class="h3"><i class="bx bx-paperclip" aria-hidden="true"></i> Dokumente</h2>
      <?php if ($documents === []): ?><p class="muted">Keine Dokumente.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($documents as $d): ?>
        <li><a href="/dokumente/<?= (int) $d['id'] ?>"><?= e($d['original_filename']) ?></a> <span class="muted small"><?= e($d['kind']) ?> · <?= e(\App\Web\Format::bytes((int) $d['size_bytes'])) ?> · <?= e(fdt($d['created_at'])) ?><?= !empty($d['visible_to_external']) ? ' · extern sichtbar' : '' ?></span></li>
      <?php endforeach; ?></ul>
      <?php if ($can['edit']): ?>
        <?= $ui->form('/vertraege/' . $id . '/dokument', $ui->field('file', 'Datei (PDF, DOCX, PNG, JPG)', ['type' => 'file', 'required' => true, 'accept' => '.pdf,.docx,.png,.jpg,.jpeg'])
          . $ui->field('kind', 'Art', ['type' => 'select', 'options' => ['attachment' => 'Anhang', 'annex' => 'Beilage', 'correspondence' => 'Korrespondenz']])
          . $ui->field('visible_external', 'Für berechtigte externe Parteien sichtbar', ['type' => 'checkbox'])
          . '<button class="btn btn-secondary" type="submit"><i class="bx bx-upload" aria-hidden="true"></i> Hochladen</button>', true) ?>
      <?php endif; ?>
    </div>

    <div class="card" id="fristen">
      <h2 class="h3"><i class="bx bx-calendar" aria-hidden="true"></i> Fristen</h2>
      <?php if ($deadlines === []): ?><p class="muted">Keine Fristen.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($deadlines as $d): ?>
        <li class="row-between"><span><strong><?= e(fdate($d['due_date'])) ?></strong> – <?= e($d['label']) ?> <span class="badge badge-neutral"><?= e($d['status'] === 'open' ? 'offen' : ($d['status'] === 'done' ? 'erledigt' : $d['status'])) ?></span></span>
          <?php if ($can['deadline'] && $d['status'] === 'open'): ?><?= $ui->action('/fristen/' . (int) $d['id'] . '/status', 'Erledigt', 'bx-check', 'btn btn-ghost btn-sm', null, ['contract_id' => $id, 'status' => 'done']) ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
      <p class="muted small">Kündigungs- und Ablauffristen werden automatisch berechnet (ohne Berücksichtigung von Feiertagen/Wochenenden – bitte verbindlich prüfen).</p>
      <?php if ($can['deadline']): ?>
        <?= $ui->form('/vertraege/' . $id . '/fristen', '<div class="grid-2">' . $ui->field('label', 'Bezeichnung', ['required' => true, 'maxlength' => 255]) . $ui->field('due_date', 'Fällig am', ['type' => 'date', 'required' => true]) . '</div>'
          . $ui->field('kind', 'Art', ['type' => 'select', 'options' => ['payment' => 'Zahlung', 'performance' => 'Leistung', 'custom' => 'Sonstiges']])
          . '<button class="btn btn-secondary" type="submit">Frist hinzufügen</button>') ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <div class="card" id="aufgaben">
      <h2 class="h3"><i class="bx bx-task" aria-hidden="true"></i> Aufgaben</h2>
      <?php if ($tasks === []): ?><p class="muted">Keine Aufgaben.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($tasks as $t): ?>
        <li class="row-between"><span><?= e($t['title']) ?> <span class="muted small"><?= $t['due_date'] ? 'bis ' . e(fdate($t['due_date'])) : '' ?> · <?= e($t['status'] === 'open' ? 'offen' : 'erledigt') ?></span></span>
          <?php if ($t['status'] === 'open' && $can['edit']): ?><?= $ui->action('/aufgaben/' . (int) $t['id'] . '/erledigen', 'Erledigt', 'bx-check', 'btn btn-ghost btn-sm', null, ['contract_id' => $id]) ?><?php endif; ?></li>
      <?php endforeach; ?></ul>
      <?php if ($can['edit']): ?>
        <?= $ui->form('/vertraege/' . $id . '/aufgaben', $ui->field('title', 'Aufgabe', ['required' => true, 'maxlength' => 255])
          . '<div class="grid-2">' . $ui->field('assignee_user_id', 'Zuständig', ['type' => 'select', 'options' => $memberOpts, 'empty' => '–']) . $ui->field('due_date', 'Fällig am', ['type' => 'date']) . '</div>'
          . '<button class="btn btn-secondary" type="submit">Aufgabe hinzufügen</button>') ?>
      <?php endif; ?>
    </div>

    <div class="card" id="kommentare">
      <h2 class="h3"><i class="bx bx-message-detail" aria-hidden="true"></i> Kommentare</h2>
      <?php if ($comments === []): ?><p class="muted">Keine Kommentare.</p><?php endif; ?>
      <ul class="list-plain"><?php foreach ($comments as $cm): ?>
        <li><p class="small muted"><?= e($cm['author'] ?? '') ?> · <?= e(fdt($cm['created_at'])) ?> · <?= $cm['visibility'] === 'internal' ? 'intern' : 'mit Gegenpartei geteilt' ?></p><p><?= nl2br(e($cm['body'])) ?></p></li>
      <?php endforeach; ?></ul>
      <?php if ($can['comment']): ?>
        <?= $ui->form('/vertraege/' . $id . '/kommentare', $ui->field('body', 'Kommentar', ['type' => 'textarea', 'rows' => 3, 'required' => true])
          . $ui->field('visibility', 'Sichtbarkeit', ['type' => 'select', 'options' => ['internal' => 'Nur intern', 'shared' => 'Mit berechtigten externen Parteien teilen']])
          . '<button class="btn btn-secondary" type="submit">Speichern</button>') ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($can['notary'] || $notary !== []): ?>
  <div class="card" id="notariat">
    <h2 class="h3"><i class="bx bx-edit" aria-hidden="true"></i> Notariat</h2>
    <?php foreach ($notary as $n): ?>
      <div class="sub-card"><div class="row-between"><strong><?= e($n['notary_office']) ?> (<?= e($n['notary_name']) ?>)</strong><span class="badge badge-neutral"><?= e($n['status']) ?></span></div>
        <p class="small muted">Bereitgestellt: <?= e(fdt($n['provided_at'])) ?> · Rückgabe: <?= e(fdt($n['returned_at'])) ?></p>
        <div class="row-gap">
          <?php if ($n['status'] === 'prepared' && $can['notary']): ?><?= $ui->action('/notariat/' . (int) $n['id'] . '/bereitstellen', 'Dem Notariat bereitstellen', 'bx-share', 'btn btn-secondary btn-sm', 'Die freigegebene Version dem Notariat zugänglich machen?', ['contract_id' => $id]) ?><?php endif; ?>
          <?php if (in_array($n['status'], ['certified', 'rejected'], true) && $can['notary']): ?><?= $ui->action('/notariat/' . (int) $n['id'] . '/intern', 'Als abgeschlossen markieren', 'bx-check', 'btn btn-secondary btn-sm', null, ['contract_id' => $id, 'status' => 'completed']) ?><?php endif; ?>
          <?php if ($n['status'] === 'inquiry' && $can['notary']): ?><?= $ui->action('/notariat/' . (int) $n['id'] . '/intern', 'Rückfrage beantwortet', 'bx-check', 'btn btn-secondary btn-sm', null, ['contract_id' => $id, 'status' => 'in_progress']) ?><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
    <?php if ($can['notary'] && in_array($status, ['approved', 'signature', 'active'], true) && $notaries !== []): ?>
      <?= $ui->form('/vertraege/' . $id . '/notariat', $ui->field('notary_user_id', 'Notariat (Benutzer)', ['type' => 'select', 'options' => $notaries, 'required' => true]) . $ui->field('office', 'Büro / Kanzlei', ['required' => true, 'maxlength' => 190]) . $ui->field('remarks', 'Hinweise', ['type' => 'textarea', 'rows' => 2])
        . '<button class="btn btn-secondary" type="submit">Notariatsfall anlegen</button>') ?>
    <?php elseif ($can['notary'] && $notaries === []): ?><p class="muted small">Es ist noch keine Notariats-Person für diesen Mandanten eingerichtet (Verwaltung → Externe Zugriffe).</p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($retention !== null): ?>
  <div class="card" id="aufbewahrung">
    <h2 class="h3"><i class="bx bx-archive" aria-hidden="true"></i> Aufbewahrung &amp; Sperren</h2>
    <?php if ($retention['policy']): ?><p>Regel: <strong><?= e($retention['policy']['name']) ?></strong> (<?= (int) $retention['policy']['retention_years'] ?> Jahre, <?= e($retention['policy']['legal_basis']) ?>)<?= $retention['policy']['legal_review_status'] !== 'reviewed' ? ' – <span class="badge badge-warn">rechtlich nicht geprüft</span>' : '' ?></p>
    <?php else: ?><p class="muted">Keine Aufbewahrungsregel zugeordnet – es erfolgt keine automatische Löschung.</p><?php endif; ?>
    <?php if ($retention['retain_until']): ?><p>Aufbewahren bis: <strong><?= e(fdate($retention['retain_until'])) ?></strong></p><?php endif; ?>
    <?php if ($retention['blocked_by'] !== []): ?><p class="small">Löschung blockiert durch: <?= e(implode('; ', $retention['blocked_by'])) ?></p><?php endif; ?>
    <?php foreach ($holds as $h): ?><p class="small"><i class="bx bx-lock-alt" aria-hidden="true"></i> Sperre seit <?= e(fdt($h['placed_at'])) ?>: <?= e($h['reason']) ?></p><?php endforeach; ?>
    <p class="small"><a href="/compliance">Zur Compliance-Verwaltung</a></p>
  </div>
  <?php endif; ?>

  <div class="card" id="weitere">
    <h2 class="h3">Weitere Aktionen</h2>
    <div class="row-gap">
      <?php if ($can['edit'] && in_array($status, ['approved', 'signature', 'active', 'expired'], true)): ?>
        <form method="post" action="/vertraege/<?= $id ?>/nachtrag" class="row-gap">
          <?= $ui->csrf() ?><label class="sr-only" for="nt">Titel des Nachtrags</label><input class="input" id="nt" name="title" placeholder="Titel des Nachtrags" maxlength="255">
          <button class="btn btn-secondary" type="submit"><i class="bx bx-git-branch" aria-hidden="true"></i> Nachtrag anlegen</button>
        </form>
      <?php endif; ?>
      <?php if ($can['archive'] && $status !== 'archived'): ?>
        <form method="post" action="/vertraege/<?= $id ?>/archivieren" class="row-gap">
          <?= $ui->csrf() ?><label class="sr-only" for="ar">Grund der Archivierung</label><input class="input" id="ar" name="reason" placeholder="Grund (Pflicht)" required maxlength="255">
          <button class="btn btn-secondary" type="submit" data-confirm="Vertrag archivieren? Archivierte Verträge sind nicht mehr änderbar."><i class="bx bx-archive-in" aria-hidden="true"></i> Archivieren</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
