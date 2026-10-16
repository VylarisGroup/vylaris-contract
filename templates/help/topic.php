<?php $v->title = $topic['title']; $n = count($topic['steps']); ?>
<section class="stack narrow guide">
  <p class="muted small"><a href="/hilfe">Hilfe</a> › <?= e(\App\Web\HelpTopics::GROUPS[$topic['group']]) ?></p>
  <header class="guide-head">
    <span class="guide-icon"><i class="bx <?= e($topic['icon']) ?>" aria-hidden="true"></i></span>
    <div>
      <h1 class="h1"><?= e($topic['title']) ?></h1>
      <p class="muted"><?= e($topic['summary']) ?></p>
      <p class="small help-meta"><i class="bx bx-time-five" aria-hidden="true"></i> ca. <?= e($topic['time']) ?> · <?= $n ?> Schritte · für <?= e(\App\Web\HelpTopics::AUDIENCES[$topic['audience']]) ?></p>
    </div>
  </header>
  <ol class="guide-steps">
    <?php foreach ($topic['steps'] as $i => [$title, $text]): ?>
      <li class="card"><span class="todo-n"><?= $i + 1 ?></span><div><h2 class="h3"><?= e($title) ?></h2><p><?= e($text) ?></p></div></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($topic['tips'] !== []): ?>
    <aside class="alert alert-info guide-tips" aria-label="Tipps"><strong><i class="bx bx-bulb" aria-hidden="true"></i> Gut zu wissen</strong>
      <ul class="list-plain bullets"><?php foreach ($topic['tips'] as $tip): ?><li><?= e($tip) ?></li><?php endforeach; ?></ul></aside>
  <?php endif; ?>
  <?php $rel = array_filter($topic['related'], static fn (string $s): bool => isset($topics[$s])); if ($rel !== []): ?>
    <h2 class="h3">Passende Anleitungen</h2>
    <div class="row-gap"><?php foreach ($rel as $s): ?><a class="btn btn-secondary btn-sm" href="/hilfe/<?= e($s) ?>"><i class="bx <?= e($topics[$s]['icon']) ?>" aria-hidden="true"></i> <?= e($topics[$s]['title']) ?></a><?php endforeach; ?></div>
  <?php endif; ?>
  <p><a href="/hilfe">← Alle Anleitungen</a></p>
</section>
