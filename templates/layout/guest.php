<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($v->title ?? 'Vertragsplattform') ?> – Vertragsplattform</title>
<script src="/assets/boot.js"></script>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/vendor/inter/inter.css">
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/vendor/boxicons/boxicons.min.css">
<?php $brandHex = isset($brand) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand) === 1 ? (string) $brand : '#4f46e5'; ?>
<link rel="stylesheet" href="/brand.css?v=<?= e(substr(md5($brandHex), 0, 8)) ?>">
</head>
<body class="guest-body">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="guest-wrap">
  <header class="guest-head">
    <span class="brand"><span class="brand-tile"><i class="bx bx-file" aria-hidden="true"></i></span> Vertragsplattform</span>
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
