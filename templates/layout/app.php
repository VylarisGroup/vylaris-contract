<?php
$int = $p !== null && !$p->isExternal();
$sysCtx = $p !== null && $p->isSystemAdmin && $p->tenantId === null;
$nav = [];
if ($p !== null) {
    $nav[] = ['/', 'Start', 'bx-home', 'Hauptbereich'];
    if ($sysCtx) {
        $nav = [['/system', 'Systemverwaltung', 'bx-server', 'System'], ['/system/einstellungen', 'Einstellungen', 'bx-cog', 'System'], ['/system/sicherungen', 'Sicherungen', 'bx-data', 'System'], ['/system/aktualisierung', 'Aktualisierung', 'bx-cloud-download', 'System']];
    } elseif ($int) {
        $nav[] = ['/vertraege', 'Verträge', 'bx-file', 'Hauptbereich'];
        if ($p->can('contract.create')) {
            $nav[] = ['/vertraege/neu', 'Neuer Vertrag', 'bx-plus-circle', 'Hauptbereich'];
        }
        if ($p->can('approval.decide') || $p->can('approval.request')) {
            $nav[] = ['/freigaben', 'Freigaben', 'bx-check-shield', 'Hauptbereich'];
        }
        if ($p->can('template.view')) {
            $nav[] = ['/vorlagen', 'Vorlagen', 'bx-layout', 'Hauptbereich'];
        }
        $nav[] = ['/gegenparteien', 'Gegenparteien', 'bx-buildings', 'Hauptbereich'];
        if ($p->can('legalhold.manage') || $p->can('retention.manage') || $p->can('privacy.manage')) {
            $nav[] = ['/compliance', 'Compliance', 'bx-shield-quarter', 'Verwaltung'];
        }
        if ($p->can('tenant.manage') || $p->can('user.manage')) {
            $nav[] = ['/verwaltung', 'Verwaltung', 'bx-cog', 'Verwaltung'];
        }
    } else {
        $nav[] = ['/vertraege', 'Verträge', 'bx-file', 'Hauptbereich'];
        if ($p->userType === 'notary') {
            $nav[] = ['/portal/notariat', 'Notariat', 'bx-edit', 'Hauptbereich'];
        }
    }
}
if ($int && !$sysCtx) {
    $nav[] = ['/benachrichtigungen', 'Benachrichtigungen', 'bx-bell', 'Hauptbereich', (int) $unread];
}
$order = ['Hauptbereich' => 0, 'Verwaltung' => 1, 'System' => 2];
usort($nav, static fn (array $a, array $b): int => ($order[$a[3]] ?? 9) <=> ($order[$b[3]] ?? 9));
$brandHex = isset($brand) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand) === 1 ? (string) $brand : '#4f46e5';
$initial = mb_strtoupper(mb_substr(trim((string) ($user['display_name'] ?? '?')), 0, 1)) ?: '?';
$isActive = static fn (string $href, string $path): bool => $path === $href || ($href !== '/' && str_starts_with($path, $href . '/') && !in_array($href, ['/vertraege', '/system'], true))
    || ($href === '/vertraege' && str_starts_with($path, '/vertraege') && !str_starts_with($path, '/vertraege/neu'))
    || ($href === '/system' && $path === '/system');
?>
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
<link rel="stylesheet" href="/brand.css?v=<?= e(substr(md5($brandHex), 0, 8)) ?>">
</head>
<body>
<div class="scroll-progress" aria-hidden="true"></div>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<?php if ($p !== null): ?>
<header class="mobilebar">
  <button type="button" class="btn btn-ghost btn-sm" data-nav-toggle aria-expanded="false" aria-controls="mainnav" aria-label="Menü"><i class="bx bx-menu text-xl" aria-hidden="true"></i></button>
  <a href="/" class="brand"><span class="brand-tile"><i class="bx bx-file" aria-hidden="true"></i></span> <span>Vertragsplattform</span></a>
</header>
<div class="backdrop" data-nav-close></div>
<aside class="sidebar" id="mainnav">
  <div class="sidebar-head"><a href="/" class="brand"><span class="brand-tile"><i class="bx bx-file" aria-hidden="true"></i></span> <span>Vertragsplattform</span></a></div>
  <nav class="mainnav" aria-label="Hauptnavigation">
    <?php $lastGroup = null; foreach ($nav as $item): [$href, $label, $icon, $group] = $item; $badge = $item[4] ?? 0; ?>
      <?php if ($group !== $lastGroup): $lastGroup = $group; ?><div class="section-label"><?= e($group) ?></div><?php endif; ?>
      <a href="<?= e($href) ?>" class="navlink<?= $isActive($href, $path) ? ' is-active' : '' ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= e($icon) ?>" aria-hidden="true"></i> <span><?= e($label) ?></span><?php if ($badge > 0): ?><span class="count" aria-label="<?= (int) $badge ?> ungelesen"><?= (int) $badge ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <details class="usermenu">
      <summary><span class="avatar" aria-hidden="true"><?= e($initial) ?></span> <span class="min-w-0"><span class="block truncate text-sm font-semibold"><?= e($user['display_name'] ?? '') ?></span><span class="block truncate text-xs muted"><?= e($user['email'] ?? '') ?></span></span></summary>
      <div class="usermenu-pop">
        <a href="/konto"><i class="bx bx-shield-alt-2" aria-hidden="true"></i> Mein Konto &amp; Sicherheit</a>
        <?php if (($user['user_type'] ?? '') === 'internal'): ?><a href="/mandant-waehlen"><i class="bx bx-transfer" aria-hidden="true"></i> Mandant wechseln</a><?php endif; ?>
        <?= $ui->action('/abmelden', 'Abmelden', 'bx-log-out', 'linklike') ?>
      </div>
    </details>
  </div>
</aside>
<?php endif; ?>
<div class="content">
<div class="toasts" aria-live="polite">
  <?php foreach ($flash as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>" data-toast="<?= e($f['type']) ?>"><i class="bx <?= ['success' => 'bx-check-circle', 'error' => 'bx-error-circle', 'warning' => 'bx-error', 'info' => 'bx-info-circle'][$f['type']] ?? 'bx-info-circle' ?> text-lg" aria-hidden="true"></i><span><?= e($f['message']) ?></span><button type="button" class="alert-close" data-toast-close aria-label="Schliessen">&times;</button></div>
  <?php endforeach; ?>
</div>
<main id="main" class="page">
  <?= $content ?>
</main>
<footer class="page-foot">
  <span>Keine Rechtsberatung – Rechtswirkung von Signaturen und Aufbewahrungsfristen ist durch Fachpersonen zu prüfen.</span>
</footer>
</div>
<script src="/assets/app.js" defer></script>
<script src="/assets/passkey.js" defer></script>
</body>
</html>
