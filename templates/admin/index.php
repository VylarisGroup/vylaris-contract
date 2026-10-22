<?php $v->title = 'Verwaltung';
$groups = [
  ['Organisation', 'Wer ist im Mandanten, und wie ist er gegliedert?', [
    ['/verwaltung/mandant', 'bx-building-house', 'Mandant & Stammdaten', 'Name, Zeitzone, Standard-Rechtsgebiet und Präfix der Vertragsnummern. Hier legen Sie fest, was das System bei neuen Verträgen vorschlägt.', 'Ihre Angaben prüfen'],
    ['/verwaltung/benutzer', 'bx-group', 'Benutzer & Rollen', 'Personen einladen, Rollen vergeben, sperren, Zugang zurücksetzen.', (int) $st['users'] . ' aktive Benutzer'],
    ['/verwaltung/abteilungen', 'bx-sitemap', 'Abteilungen', 'Zugriffsbereiche: Verträge einer Abteilung sehen nur deren Mitglieder (und Mandantenadministration).', (int) $st['departments'] . ' Abteilungen'],
  ]],
  ['Verträge anpassen', 'Wie sollen Verträge bei Ihnen aussehen?', [
    ['/verwaltung/vertragsarten', 'bx-category', 'Vertragsarten', 'Kategorien wie «Dienstleistung» oder «Miete». Es gibt Standardarten; eigene ergänzen Sie hier.', (int) $st['types'] . ' eigene Arten'],
    ['/verwaltung/felder', 'bx-list-plus', 'Zusatzfelder', 'Eigene Felder in jedem Vertrag, z. B. «Kostenstelle».', (int) $st['fields'] . ' Felder'],
    ['/vorlagen', 'bx-layout', 'Vorlagen & Klauseln', 'Wiederverwendbare Vertragstexte mit Fragebogen. Das spart am meisten Zeit.', (int) $st['templates'] . ' Vorlagen'],
    ['/gegenparteien', 'bx-buildings', 'Gegenparteien', 'Vertragspartner verbinden Sie über den Mandanten-Code.', (int) $st['connections'] . ' Verbindungen'],
  ]],
  ['Zugriff & Sicherheit', 'Wer darf wie auf die Plattform?', [
    ['/verwaltung/sso', 'bx-key', 'Single Sign-on', 'Anmeldung über Ihren Identitätsanbieter (optional).', $st['sso'] > 0 ? 'aktiv' : 'nicht eingerichtet'],
    ['/verwaltung/mandate', 'bx-user-voice', 'Externe Zugriffe', 'Befristeter Zugang für Gegenparteien, Revisionsstelle und Notariat auf einzelne Verträge.', (int) $st['mandates'] . ' aktiv'],
    ['/verwaltung/protokoll', 'bx-list-check', 'Protokoll', 'Wer hat wann was getan? Sicherheits- und Änderungsprotokoll.', 'nur lesen'],
  ]],
  ['Recht & Aufbewahrung', 'Aufbewahrungsfristen, Sperren und Datenschutz.', [
    ['/compliance', 'bx-shield-quarter', 'Compliance', 'Aufbewahrungsregeln, rechtliche Sperren, Datenschutzanfragen. Rechtliche Angaben sind fachlich zu prüfen.', (int) $st['retention'] . ' Regeln'],
  ]],
];
$todo = [
  [true, 'Stammdaten prüfen', '/verwaltung/mandant', 'Name, Zeitzone, Rechtsgebiet, Präfix.'],
  [$st['users'] > 1, 'Team einladen', '/verwaltung/benutzer', 'Mindestens eine weitere Person.'],
  [$st['approvers'] > 0, 'Freigebende festlegen', '/verwaltung/benutzer', 'Personen mit Rolle «Freigeber», «Vertragsverantwortliche» oder «Administration» können freigeben.'],
  [$st['templates'] > 0, 'Erste Vorlage anlegen', '/vorlagen', 'Optional, aber der schnellste Weg zu neuen Verträgen.'],
  [$st['connections'] > 0, 'Mit Vertragspartnern verbinden', '/gegenparteien', 'Optional: Mandanten-Code austauschen.'],
  [$st['retention'] > 0, 'Aufbewahrungsregel festlegen', '/compliance', 'Optional; ohne Regel wird nie automatisch gelöscht.'],
];
?>
<section class="stack">
  <h1 class="h1">Verwaltung</h1>
  <p class="muted">Hier richten Sie Ihren Mandanten ein. Die Bereiche sind nach Zweck geordnet; unter jeder Kachel steht, was Sie dort tun und wie der Stand ist.</p>

  <div class="card">
    <h2 class="h3"><i class="bx bx-list-check" aria-hidden="true"></i> Einrichtung</h2>
    <ul class="checks"><?php foreach ($todo as [$ok, $t, $href, $d]): ?>
      <li><span class="badge <?= $ok ? 'badge-ok' : 'badge-warn' ?>"><?= $ok ? 'erledigt' : 'offen' ?></span> <a href="<?= e($href) ?>"><?= e($t) ?></a> <span class="muted small">– <?= e($d) ?></span></li>
    <?php endforeach; ?></ul>
  </div>

  <?php foreach ($groups as [$gt, $gd, $items]): ?>
    <div class="stack">
      <div><h2 class="h2"><?= e($gt) ?></h2><p class="muted small"><?= e($gd) ?></p></div>
      <div class="grid-3 tiles">
        <?php foreach ($items as [$href, $icon, $t, $d, $badge]): ?>
          <a class="tile" href="<?= e($href) ?>"><i class="bx <?= e($icon) ?>" aria-hidden="true"></i><strong><?= e($t) ?></strong><span class="muted small"><?= e($d) ?></span><span class="badge badge-neutral"><?= e($badge) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <h2 class="h3"><i class="bx bx-id-card" aria-hidden="true"></i> Wer darf was?</h2>
    <div class="table-wrap"><table class="table table-sm"><thead><tr><th scope="col">Rolle</th><th scope="col">Darf</th></tr></thead><tbody>
      <tr><th scope="row">Mandantenadministration</th><td>Alles in diesem Mandanten: Verwaltung, alle Verträge, Freigabe, Unterzeichnung, Compliance.</td></tr>
      <tr><th scope="row">Vertragsverantwortliche</th><td>Verträge erfassen und bearbeiten, Freigabe und Unterzeichnung anfordern, selbst freigeben, Vorlagen pflegen – für Verträge der eigenen Abteilungen und eigene Verträge.</td></tr>
      <tr><th scope="row">Freigeber</th><td>Verträge einsehen und freigeben oder ablehnen; Vorlagen prüfen.</td></tr>
      <tr><th scope="row">Leser</th><td>Verträge der eigenen Abteilung ansehen und herunterladen.</td></tr>
    </tbody></table></div>
    <p class="muted small">Externe Personen (Gegenparteien, Revision, Notariat) erhalten nie Mandantenrechte, sondern befristete Zugriffe auf einzelne Verträge («Externe Zugriffe»).</p>
  </div>
  <p class="muted small">Ausführlich erklärt: <a href="/hilfe/mandant-einrichten">Den eigenen Mandanten einrichten</a>, <a href="/hilfe/benutzer-rollen">Benutzer einladen und Rollen vergeben</a>.</p>
</section>
