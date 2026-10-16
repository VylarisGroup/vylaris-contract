<?php $v->title = 'Benachrichtigungen'; ?>
<section class="stack">
  <h1 class="h1">Benachrichtigungen</h1>
  <?php if ($items === []): ?><p class="muted">Keine Benachrichtigungen.</p><?php endif; ?>
  <ul class="list-cards">
  <?php foreach ($items as $n): ?>
    <li class="card<?= $n['read_at'] === null ? ' card-unread' : '' ?>">
      <div class="row-between">
        <strong><?= e($n['subject']) ?></strong>
        <span class="muted small"><?= e(fdt($n['created_at'])) ?></span>
      </div>
      <p><?= e($n['body']) ?></p>
      <div class="row-gap">
        <?php if ($n['link_path']): ?><a class="btn btn-secondary btn-sm" href="<?= e($n['link_path']) ?>">Öffnen</a><?php endif; ?>
        <?php if ($n['read_at'] === null): ?><?= $ui->action('/benachrichtigungen/' . (int) $n['id'] . '/gelesen', 'Als gelesen markieren', 'bx-check', 'btn btn-ghost btn-sm') ?><?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
