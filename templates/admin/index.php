<?php $v->title = 'Verwaltung'; ?>
<section class="stack">
  <h1 class="h1">Verwaltung</h1>
  <div class="grid-3 tiles">
    <?php foreach ([
      ['/verwaltung/benutzer', 'bx-group', 'Benutzer & Rollen', 'Einladen, Rollen zuweisen, sperren, zweiten Faktor zurücksetzen'],
      ['/verwaltung/abteilungen', 'bx-sitemap', 'Abteilungen', 'Zugriffsbereiche für Verträge'],
      ['/verwaltung/mandant', 'bx-slider', 'Mandant & Felder', 'Stammdaten, Vertragsarten, Zusatzfelder'],
      ['/verwaltung/mandate', 'bx-user-voice', 'Externe Zugriffe', 'Gegenparteien, Revisionsstelle, Notariat (befristet)'],
      ['/verwaltung/protokoll', 'bx-list-check', 'Protokoll', 'Sicherheits- und Änderungsprotokoll des Mandanten'],
    ] as [$href, $icon, $t, $d]): ?>
      <a class="tile" href="<?= e($href) ?>"><i class="bx <?= e($icon) ?>" aria-hidden="true"></i><strong><?= e($t) ?></strong><span class="muted small"><?= e($d) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>
