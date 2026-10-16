<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($v->title ?? 'Vertragsplattform') ?> – Vertragsplattform</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<script src="/assets/theme.js"></script>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/vendor/boxicons/boxicons.min.css">
</head>
<body class="guest-body">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="guest-wrap">
  <header class="guest-head">
    <span class="brand"><i class="bx bx-file" aria-hidden="true"></i> Vertragsplattform</span>
    <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle aria-label="Farbschema wechseln"><i class="bx bx-adjust" aria-hidden="true"></i></button>
  </header>
  <main id="main" class="guest-card">
    <?php foreach ($flash as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <footer class="guest-foot">Diese Software ersetzt keine Rechtsberatung. Elektronische Signaturen werden von externen Anbietern erzeugt.</footer>
</div>
<script src="/assets/app.js" defer></script>
</body>
</html>
