<?php $v->title = $title ?? 'Hinweis'; ?>
<section class="stack">
  <h1 class="h1"><?= e($title) ?></h1>
  <p><?= e($text ?? '') ?></p>
  <p><a class="btn btn-secondary" href="/"><i class="bx bx-home" aria-hidden="true"></i> Zur Startseite</a></p>
</section>
