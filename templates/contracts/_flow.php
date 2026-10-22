<?php
/** Ablauf-Panel der Vertragsseite: was das System übernimmt, was die Person tut. Erwartet $c, $status, $latestVersion, $approvals, $signatures, $deadlines, $can, $approverOpts, $preview. */
$hasText = $preview !== '' || !empty($c['has_text']);
$hasParties = $c['parties'] !== [];
$pendingNames = array_values(array_unique(array_map(static fn (array $a): string => (string) $a['approver_name'], array_filter($approvals, static fn (array $a): bool => $a['status'] === 'pending'))));
$sigOpen = false; $sigDone = false;
foreach ($signatures as $sg) { if (in_array($sg['status'], ['prepared', 'submitted', 'partially_signed'], true)) { $sigOpen = true; } if ($sg['status'] === 'signed') { $sigDone = true; } }
// aktuelle Stufe (1–5) und Hinweis
$cur = 1; $now = ''; $btn = null;
if (in_array($status, ['draft', 'rejected', 'withdrawn'], true)) {
    if (!$hasText) { $cur = 2; $now = 'Als Nächstes brauchen Sie den Vertragstext.'; $btn = ['#text', 'Vertragstext hinzufügen']; }
    else { $cur = 3; $now = $status === 'rejected' ? 'Die Freigabe wurde abgelehnt. Prüfen Sie die Begründung, passen Sie den Text an und fordern Sie die Freigabe erneut an.' : ($status === 'withdrawn' ? 'Die Freigabeanfrage wurde zurückgezogen. Sie können sie erneut anfordern.' : 'Der Text ist erfasst. Als Nächstes holen Sie die Freigabe ein.'); $btn = ['#freigabe', 'Freigabe anfordern']; }
} elseif ($status === 'review') { $cur = 3; $now = 'Wartet auf die Entscheidung von ' . ($pendingNames !== [] ? implode(', ', $pendingNames) : 'den Freigebenden') . '. Sie müssen nichts tun; die Personen wurden benachrichtigt.'; }
elseif ($status === 'approved') { $cur = 4; $now = 'Freigegeben. Jetzt kann die Unterzeichnung angefordert werden.'; $btn = ['#signatur', 'Unterzeichnung anfordern']; }
elseif ($status === 'signature') { $cur = 4; $now = $sigOpen ? 'Die Unterzeichnung läuft. Das System lädt ein, prüft Codes und schliesst nach der letzten Unterschrift automatisch ab.' : 'Unterzeichnung läuft.'; }
elseif (in_array($status, ['active', 'expired', 'archived', 'cancelled'], true)) { $cur = 5; $now = $status === 'active' ? 'Der Vertrag läuft. Das System überwacht die Fristen und erinnert Sie rechtzeitig.' : ''; }
$steps = [
  [1, 'Erfassen', 'Vertragsnummer, Eckdaten, Parteien, Kündigungs- und Ablauffristen werden vom System gespeichert bzw. berechnet.', 'Eckdaten und Parteien prüfen und bei Bedarf ergänzen.'],
  [2, 'Vertragstext', 'Jede Fassung wird als Version mit Prüfsumme gesichert; Word-Text wird übernommen; PDF/DOCX-Export steht bereit.', 'Text aus Vorlage übernehmen, Datei importieren oder selbst schreiben.'],
  [3, 'Freigabe', 'Die Textfassung wird gesperrt, die Freigebenden werden benachrichtigt, jeder Entscheid wird protokolliert. Eine spätere Textänderung macht die Freigabe ungültig.', 'Person(en) auswählen, die freigeben (auch Sie selbst sind möglich).'],
  [4, 'Unterzeichnung', 'Einladung per E-Mail, Einmalcode, Signaturprotokoll und Siegel; nach der letzten Unterschrift wird der Vertrag automatisch aktiv.', 'Unterzeichnende bestätigen (aus den Parteien vorausgefüllt) und Signaturstufe wählen.'],
  [5, 'Laufzeit', 'Fristen werden überwacht und erinnert, abgelaufene Verträge automatisch markiert, Aufbewahrung nach Regel.', 'Erinnerungen quittieren, bei Änderungen einen Nachtrag anlegen, am Ende archivieren.'],
];
?>
<div class="card flow" id="ablauf">
  <h2 class="h3"><i class="bx bx-map-alt" aria-hidden="true"></i> Ablauf dieses Vertrags</h2>
  <ol class="flow-steps">
  <?php foreach ($steps as [$n, $title, $sys, $you]): $state = $n < $cur ? 'done' : ($n === $cur ? 'current' : 'todo'); ?>
    <li class="flow-step flow-<?= e($state) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
      <span class="flow-n"><?= $state === 'done' ? '✓' : (int) $n ?></span>
      <div><strong><?= e($title) ?></strong>
        <?php if ($state !== 'todo'): ?><p class="small"><span class="badge badge-info">System</span> <?= e($sys) ?></p><p class="small"><span class="badge badge-neutral">Sie</span> <?= e($you) ?></p><?php endif; ?></div>
    </li>
  <?php endforeach; ?>
  </ol>
  <?php if ($now !== ''): ?><div class="alert alert-info" role="status"><strong>Jetzt:</strong> <?= e($now) ?><?php if ($btn !== null): ?> <a class="btn btn-primary btn-sm" href="<?= e($btn[0]) ?>"><?= e($btn[1]) ?></a><?php endif; ?></div><?php endif; ?>
  <?php if ($status === 'draft' && !$hasParties): ?><p class="small muted"><i class="bx bx-info-circle" aria-hidden="true"></i> Es sind noch keine Parteien erfasst. Ergänzen Sie diese unter «Bearbeiten»; sie werden später für die Unterzeichnung vorausgefüllt.</p><?php endif; ?>
</div>
