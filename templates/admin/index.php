<?php $v->title = 'Verwaltung'; ?>
<section class="stack">
  <h1 class="h1">Verwaltung</h1>
  <div class="grid-3 tiles">
    <?php foreach ([
      ['/verwaltung/benutzer', 'bx-group', 'Benutzer & Rollen', 'Einladen, Rollen zuweisen, sperren, zweiten Faktor zurücksetzen'],
      ['/verwaltung/abteilungen', 'bx-sitemap', 'Abteilungen', 'Zugriffsbereiche für Verträge'],
      ['/verwaltung/mandant', 'bx-slider', 'Mandant & Felder', 'Stammdaten, Vertragsarten, Zusatzfelder'],
      ['/verwaltung/sso', 'bx-key', 'Single Sign-on', 'Anmeldung über den Identitätsanbieter Ihrer Organisation (OIDC)'],
      ['/verwaltung/mandate', 'bx-user-voice', 'Externe Zugriffe', 'Gegenparteien, Revisionsstelle, Notariat (befristet)'],
      ['/verwaltung/protokoll', 'bx-list-check', 'Protokoll', 'Sicherheits- und Änderungsprotokoll des Mandanten'],
    ] as [$href, $icon, $t, $d]): ?>
      <a class="tile" href="<?= e($href) ?>"><i class="bx <?= e($icon) ?>" aria-hidden="true"></i><strong><?= e($t) ?></strong><span class="muted small"><?= e($d) ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h2 class="h3"><i class="bx bx-help-circle" aria-hidden="true"></i> Erste Schritte für Ihre Organisation</h2>
    <ol class="todo">
      <li><span class="todo-n">1</span><div><a href="/verwaltung/mandant">Stammdaten prüfen</a> – Name, Zeitzone, Präfix der Vertragsnummern.</div></li>
      <li><span class="todo-n">2</span><div><a href="/verwaltung/vertragsarten">Vertragsarten festlegen</a> und bei Bedarf <a href="/verwaltung/felder">Zusatzfelder</a> anlegen.</div></li>
      <li><span class="todo-n">3</span><div><a href="/verwaltung/benutzer">Team einladen</a> und <a href="/verwaltung/abteilungen">Abteilungen</a> bilden.</div></li>
    </ol>
    <p class="muted small">Ausführlich erklärt: <a href="/hilfe/mandant-einrichten">Den eigenen Mandanten einrichten</a>, <a href="/hilfe/benutzer-rollen">Benutzer einladen und Rollen vergeben</a>.</p>
  </div>
</section>
