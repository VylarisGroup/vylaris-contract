<?php $v->title = 'Klauselbibliothek'; ?>
<section class="stack">
  <p class="muted small"><a href="/vorlagen">Vorlagen</a></p>
  <h1 class="h1">Klauselbibliothek</h1>
  <?php foreach ($clauses as $c): ?>
    <div class="card"><div class="row-between"><strong><code><?= e($c['code']) ?></code> – <?= e($c['title']) ?></strong>
      <span class="badge <?= $c['review_status'] === 'approved' ? 'badge-ok' : 'badge-warn' ?>"><?= e($c['review_status']) ?></span></div>
      <div class="doc-preview small"><?= \App\Core\HtmlSanitizer::clean((string) $c['body']) ?></div>
      <?php if ($p->can('template.approve')): ?><div class="row-gap">
        <?= $ui->action('/klauseln/' . (int) $c['id'] . '/pruefung', 'Freigeben', 'bx-check', 'btn btn-secondary btn-sm', null, ['status' => 'approved']) ?>
        <?= $ui->action('/klauseln/' . (int) $c['id'] . '/pruefung', 'Zurücksetzen', 'bx-undo', 'btn btn-ghost btn-sm', null, ['status' => 'unreviewed']) ?></div><?php endif; ?></div>
  <?php endforeach; ?>
  <?php if ($p->can('template.manage')): ?>
  <div class="card"><h2 class="h3">Klausel anlegen / ändern</h2>
    <p class="muted small">Eine Änderung setzt den Prüfstatus zurück. Um eine bestehende Klausel zu ändern, denselben Code verwenden.</p>
    <?= $ui->form('/klauseln', '<div class="grid-2">' . $ui->field('code', 'Code (GROSSBUCHSTABEN)', ['required' => true, 'maxlength' => 40, 'pattern' => '[A-Za-z0-9_]+']) . $ui->field('title', 'Titel', ['required' => true, 'maxlength' => 190]) . '</div>'
      . $ui->field('body', 'Text (HTML)', ['type' => 'textarea', 'rows' => 6, 'required' => true]) . '<button class="btn btn-primary" type="submit">Speichern</button>') ?></div>
  <?php endif; ?>
</section>
