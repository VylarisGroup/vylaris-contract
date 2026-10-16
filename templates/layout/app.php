<?php
$int = $p !== null && !$p->isExternal();
$sysCtx = $p !== null && $p->isSystemAdmin && $p->tenantId === null;
$nav = [];
if ($p !== null) {
    $nav[] = ['/', 'Start', 'bx-home'];
    if ($sysCtx) {
        $nav = [['/system', 'System', 'bx-server']];
    } elseif ($int) {
        $nav[] = ['/vertraege', 'Verträge', 'bx-file'];
        if ($p->can('contract.create')) {
            $nav[] = ['/vertraege/neu', 'Neu', 'bx-plus-circle'];
        }
        if ($p->can('approval.decide') || $p->can('approval.request')) {
            $nav[] = ['/freigaben', 'Freigaben', 'bx-check-shield'];
        }
        if ($p->can('template.view')) {
            $nav[] = ['/vorlagen', 'Vorlagen', 'bx-layout'];
        }
        $nav[] = ['/gegenparteien', 'Gegenparteien', 'bx-buildings'];
        if ($p->can('legalhold.manage') || $p->can('retention.manage') || $p->can('privacy.manage')) {
            $nav[] = ['/compliance', 'Compliance', 'bx-shield-quarter'];
        }
        if ($p->can('tenant.manage') || $p->can('user.manage')) {
            $nav[] = ['/verwaltung', 'Verwaltung', 'bx-cog'];
        }
    } else {
        $nav[] = ['/vertraege', 'Verträge', 'bx-file'];
        if ($p->userType === 'notary') {
            $nav[] = ['/portal/notariat', 'Notariat', 'bx-edit'];
        }
    }
}
?>
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
<body>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<header class="topbar">
  <div class="topbar-in">
    <a href="/" class="brand"><i class="bx bx-file" aria-hidden="true"></i> <span>Vertragsplattform</span></a>
    <?php if ($p !== null): ?>
    <button type="button" class="btn btn-ghost btn-sm nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="mainnav" aria-label="Menü"><i class="bx bx-menu" aria-hidden="true"></i></button>
    <nav id="mainnav" class="mainnav" aria-label="Hauptnavigation">
      <?php foreach ($nav as [$href, $label, $icon]): ?>
        <a href="<?= e($href) ?>" class="navlink<?= ($path === $href || ($href !== '/' && str_starts_with($path, $href) && $href !== '/vertraege/neu' && !($href === '/vertraege' && str_starts_with($path, '/vertraege/neu')))) ? ' is-active' : '' ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
      <?php if ($int && !$sysCtx): ?>
        <a class="btn btn-ghost btn-sm" href="/benachrichtigungen" aria-label="Benachrichtigungen<?= $unread > 0 ? ', ' . (int) $unread . ' ungelesen' : '' ?>"><i class="bx bx-bell" aria-hidden="true"></i><?php if ($unread > 0): ?><span class="count"><?= (int) $unread ?></span><?php endif; ?></a>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle aria-label="Farbschema wechseln"><i class="bx bx-adjust" aria-hidden="true"></i></button>
      <details class="usermenu">
        <summary class="btn btn-ghost btn-sm"><i class="bx bx-user-circle" aria-hidden="true"></i> <span class="hide-sm"><?= e($user['display_name'] ?? '') ?></span></summary>
        <div class="usermenu-pop">
          <p class="muted small"><?= e($user['email'] ?? '') ?></p>
          <a href="/konto">Mein Konto &amp; Sicherheit</a>
          <?php if (($user['user_type'] ?? '') === 'internal'): ?><a href="/mandant-waehlen">Mandant wechseln</a><?php endif; ?>
          <?= $ui->action('/abmelden', 'Abmelden', 'bx-log-out', 'linklike') ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="page">
  <?php foreach ($flash as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="page-foot">
  <span>Vertragsplattform</span> ·
  <span>Keine Rechtsberatung – Rechtswirkung von Signaturen und Aufbewahrungsfristen ist durch Fachpersonen zu prüfen.</span>
</footer>
<script src="/assets/app.js" defer></script>
</body>
</html>
