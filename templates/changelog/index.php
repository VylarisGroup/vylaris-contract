<?php
// Klassen für Tailwind sichtbar halten (dynamisch zusammengesetzt): changelog-neu changelog-verbessert changelog-behoben changelog-wichtig
$v->title = 'Neuigkeiten';
$render = static function (array $e, bool $open) use ($ui): void { ?>
  <article class="card changelog-entry">
    <header class="row-between">
      <h2 class="h2">Version <?= e($e['version']) ?></h2>
      <?php if ($e['date'] !== ''): ?><span class="muted small"><?= e($e['date']) ?></span><?php endif; ?>
    </header>
    <?php if ($e['intro'] !== ''): ?><p class="muted"><?= e($e['intro']) ?></p><?php endif; ?>
    <?php foreach (\App\Modules\Maintenance\ChangelogService::SECTIONS as $name => $icon): if (empty($e['sections'][$name])): continue; endif; ?>
      <div class="changelog-sec changelog-<?= e(strtolower($name)) ?>">
        <h3 class="h3"><i class="bx <?= e($icon) ?>" aria-hidden="true"></i> <?= e($name) ?></h3>
        <ul class="list-plain bullets"><?php foreach ($e['sections'][$name] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endforeach; ?>
  </article>
<?php }; ?>
<section class="stack narrow">
  <header>
    <h1 class="h1"><i class="bx bx-gift" aria-hidden="true"></i> <?= $unseen ? 'Was ist neu?' : 'Neuigkeiten' ?></h1>
    <p class="muted"><?= $unseen ? 'Die Plattform wurde aktualisiert. Das hat sich seit Ihrem letzten Besuch geändert:' : 'Änderungsverlauf der Plattform. Installierte Version: ' . e($current) . '.' ?></p>
  </header>
  <?php foreach ($new as $e): $render($e, true); endforeach; ?>
  <?php if ($unseen): ?>
    <?= $ui->form('/neuigkeiten/gelesen', '<input type="hidden" name="weiter" value="' . e($next) . '"><button class="btn btn-primary" type="submit"><i class="bx bx-check" aria-hidden="true"></i> Verstanden, weiter</button>') ?>
  <?php endif; ?>
  <?php if ($older !== []): ?>
    <h2 class="h2"><?= $unseen ? 'Frühere Versionen' : 'Alle Versionen' ?></h2>
    <?php foreach ($older as $e): $render($e, false); endforeach; ?>
  <?php endif; ?>
  <?php if ($new === [] && $older === []): ?><div class="card empty"><i class="bx bx-file-blank" aria-hidden="true"></i><p>Es liegt kein Änderungsverlauf vor.</p></div><?php endif; ?>
</section>
