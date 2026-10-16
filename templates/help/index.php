<?php
$v->title = 'Hilfe';
$groups = \App\Web\HelpTopics::GROUPS;
?>
<section class="stack">
  <div>
    <h1 class="h1">Hilfe &amp; Anleitungen</h1>
    <p class="muted">Schritt-für-Schritt-Anleitungen für Ihre Aufgaben. Wählen Sie ein Thema oder suchen Sie nach einem Stichwort.</p>
  </div>
  <div class="field help-search"><label class="label" for="help-q">Anleitung suchen</label>
    <input class="input" id="help-q" type="search" placeholder="z. B. Passkey, Frist, Cronjob …" autocomplete="off" data-help-search></div>
  <p class="muted" data-help-empty hidden role="status">Keine passende Anleitung gefunden.</p>
  <?php foreach ($groups as $gk => $gl): $in = array_filter($topics, static fn (array $t): bool => $t['group'] === $gk); if ($in === []) { continue; } ?>
    <div data-help-group>
      <h2 class="h2"><?= e($gl) ?></h2>
      <div class="grid-3 tiles">
        <?php foreach ($in as $slug => $t): ?>
          <a class="tile" href="/hilfe/<?= e($slug) ?>" data-help-item data-text="<?= e(mb_strtolower($t['title'] . ' ' . $t['summary'] . ' ' . implode(' ', array_column($t['steps'], 0)))) ?>">
            <i class="bx <?= e($t['icon']) ?>" aria-hidden="true"></i><strong><?= e($t['title']) ?></strong>
            <span class="muted small"><?= e($t['summary']) ?></span>
            <span class="small help-meta"><i class="bx bx-time-five" aria-hidden="true"></i> <?= e($t['time']) ?> · <?= e(\App\Web\HelpTopics::AUDIENCES[$t['audience']]) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <p class="muted small">Ausführliche technische Unterlagen liegen im Ordner <code>docs/</code> des Projekts. Diese Anleitungen ersetzen keine Rechtsberatung.</p>
  <script src="/assets/help.js" defer></script>
</section>
