<?php
$int = $p !== null && !$p->isExternal();
$sysCtx = $p !== null && $p->isSystemAdmin && $p->tenantId === null;
$nav = [];
if ($p !== null) {
    $nav[] = ['/', 'Start', 'bx-home', 'Hauptbereich'];
    if ($sysCtx) {
        $nav = [];
        foreach ([['/system', 'Übersicht', 'bx-server', 'System'], ['/system/mandanten', 'Mandanten', 'bx-buildings', 'System'], ['/system/diagnose', 'Diagnose', 'bx-pulse', 'System'], ['/system/audit-kette', 'Audit-Kette', 'bx-link-alt', 'System'], ['/system/aufgaben', 'Aufgaben', 'bx-timer', 'System'],
            ['/system/einstellungen/darstellung', 'Darstellung', 'bx-palette', 'Einstellungen'], ['/system/einstellungen/adresse', 'Adresse & Netzwerk', 'bx-globe', 'Einstellungen'], ['/system/einstellungen/uploads', 'Uploads', 'bx-upload', 'Einstellungen'], ['/system/einstellungen/email-server', 'E-Mail-Server', 'bx-envelope', 'Einstellungen'], ['/system/einstellungen/absender', 'Absender', 'bx-at', 'Einstellungen'], ['/system/einstellungen/signatur', 'Signatur', 'bx-pen', 'Einstellungen'], ['/system/einstellungen/skribble', 'Skribble', 'bx-edit', 'Einstellungen'],
            ['/system/sicherungen', 'Sicherungen', 'bx-data', 'Betrieb'], ['/system/aktualisierung', 'Aktualisierung', 'bx-cloud-download', 'Betrieb']] as $n) {
            $nav[] = $n;
        }
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
        $nav[] = ['/geteilt', 'Geteilte Verträge', 'bx-share-alt', 'Hauptbereich'];
        if ($p->can('legalhold.manage') || $p->can('retention.manage') || $p->can('privacy.manage')) {
            $nav[] = ['/compliance', 'Compliance', 'bx-shield-quarter', 'Verwaltung'];
        }
        if ($p->can('tenant.manage') || $p->can('user.manage')) {
            $nav[] = ['/verwaltung', 'Verwaltung', 'bx-cog', 'Verwaltung'];
            if ($p->can('tenant.manage')) {
                $nav[] = ['/verwaltung/mandant', 'Mandant', 'bx-building-house', 'Verwaltung'];
                $nav[] = ['/verwaltung/vertragsarten', 'Vertragsarten', 'bx-category', 'Verwaltung'];
                $nav[] = ['/verwaltung/felder', 'Zusatzfelder', 'bx-list-plus', 'Verwaltung'];
                $nav[] = ['/verwaltung/sso', 'Single Sign-on', 'bx-key', 'Verwaltung'];
            }
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
if ($p !== null) {
    $nav[] = ['/hilfe', 'Hilfe & Anleitungen', 'bx-help-circle', 'Hilfe'];
}
$order = ['Hauptbereich' => 0, 'Verwaltung' => 1, 'System' => 2, 'Einstellungen' => 3, 'Betrieb' => 4, 'Hilfe' => 5];
usort($nav, static fn (array $a, array $b): int => ($order[$a[3]] ?? 9) <=> ($order[$b[3]] ?? 9));
$brandHex = isset($brand) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand) === 1 ? (string) $brand : '#4f46e5';
$initial = mb_strtoupper(mb_substr(trim((string) ($user['display_name'] ?? '?')), 0, 1)) ?: '?';
$isActive = static fn (string $href, string $path): bool => $path === $href || ($href !== '/' && str_starts_with($path, $href . '/') && !in_array($href, ['/vertraege', '/system', '/verwaltung'], true))
    || ($href === '/vertraege' && str_starts_with($path, '/vertraege') && !str_starts_with($path, '/vertraege/neu'))
    || ($href === '/system' && $path === '/system') || ($href === '/verwaltung' && $path === '/verwaltung');
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
    <?php
    $groups = [];
    foreach ($nav as $item) { $groups[$item[3]][] = $item; }
    $groupIcons = ['Hauptbereich' => 'bx-home-alt', 'Verwaltung' => 'bx-cog', 'System' => 'bx-server', 'Einstellungen' => 'bx-slider-alt', 'Betrieb' => 'bx-wrench', 'Hilfe' => 'bx-help-circle'];
    $groupKey = static fn (string $g): string => strtolower(preg_replace('/[^A-Za-z]/', '', $g) ?? '');
    foreach ($groups as $gname => $items):
        $hasActive = false; $sum = 0;
        foreach ($items as $it) { $hasActive = $hasActive || $isActive($it[0], $path); $sum += (int) ($it[4] ?? 0); }
        $openDefault = $hasActive || in_array($gname, ['Hauptbereich', 'System'], true);
    ?>
      <details class="navgroup" data-navgroup="<?= e($groupKey($gname)) ?>"<?= $hasActive ? ' data-active="1"' : '' ?><?= $openDefault ? ' open' : '' ?>>
        <summary class="section-label"><i class="bx <?= e($groupIcons[$gname] ?? 'bx-folder') ?>" aria-hidden="true"></i><span><?= e($gname) ?></span><?php if ($sum > 0): ?><span class="count count-collapsed" aria-label="<?= $sum ?> ungelesen"><?= $sum ?></span><?php endif; ?><i class="bx bx-chevron-down navgroup-chev" aria-hidden="true"></i></summary>
        <div class="navgroup-items">
        <?php foreach ($items as $item): [$href, $label, $icon] = $item; $badge = $item[4] ?? 0; ?>
          <a href="<?= e($href) ?>" class="navlink<?= $isActive($href, $path) ? ' is-active' : '' ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= e($icon) ?>" aria-hidden="true"></i> <span><?= e($label) ?></span><?php if ($badge > 0): ?><span class="count" aria-label="<?= (int) $badge ?> ungelesen"><?= (int) $badge ?></span><?php endif; ?></a>
        <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <details class="usermenu">
      <summary><span class="avatar" aria-hidden="true"><?= e($initial) ?></span> <span class="min-w-0"><span class="block truncate text-sm font-semibold"><?= e($user['display_name'] ?? '') ?></span><span class="block truncate text-xs muted"><?= e($user['email'] ?? '') ?></span></span></summary>
      <div class="usermenu-pop">
        <a href="/hilfe"><i class="bx bx-help-circle" aria-hidden="true"></i> Hilfe &amp; Anleitungen</a>
        <?php if ($p !== null && ($p->isSystemAdmin || $p->can('tenant.manage') || $p->can('user.manage'))): ?><a href="/neuigkeiten"><i class="bx bx-gift" aria-hidden="true"></i> Neuigkeiten</a><?php endif; ?>
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
<?php if (!empty($changelogUnseen)): ?>
<div class="update-banner" role="status"><i class="bx bx-gift" aria-hidden="true"></i> <span>Die Plattform wurde auf <strong>Version <?= e($changelogUnseen) ?></strong> aktualisiert.</span> <a href="/neuigkeiten">Neuigkeiten ansehen</a></div>
<?php endif; ?>
<?php
$helpMap = ['/vertraege/neu' => 'vertrag-erfassen', '/freigaben' => 'vertrag-freigabe', '/vertraege' => 'vertrag-erfassen', '/vorlagen' => 'vorlagen', '/klauseln' => 'vorlagen', '/gegenparteien' => 'gegenparteien',
    '/verwaltung/benutzer' => 'benutzer-rollen', '/verwaltung/mandant' => 'mandant-einrichten', '/verwaltung/vertragsarten' => 'mandant-einrichten', '/verwaltung/felder' => 'mandant-einrichten', '/verwaltung/sso' => 'sso',
    '/konto' => 'konto-sicherheit', '/system/mandanten' => 'mandanten-verwalten', '/system/aufgaben' => 'cronjob', '/system/sicherungen' => 'sicherung', '/system/aktualisierung' => 'aktualisieren',
    '/system/einstellungen/email-server' => 'email-einrichten', '/system/einstellungen/absender' => 'email-einrichten', '/system/einstellungen/signatur' => 'signaturanbieter', '/system/einstellungen/skribble' => 'signaturanbieter'];
$helpSlug = null;
foreach ($helpMap as $prefix => $slug) {
    if ($path === $prefix || str_starts_with($path, $prefix . '/')) { $helpSlug = $slug; break; }
}
if ($helpSlug !== null && $p !== null && isset(\App\Web\HelpTopics::visibleFor($p->isSystemAdmin, $p->can('user.manage') || $p->can('tenant.manage'), $p->userType === 'internal')[$helpSlug])): ?>
<a class="help-link" href="/hilfe/<?= e($helpSlug) ?>"><i class="bx bx-help-circle" aria-hidden="true"></i> Anleitung zu dieser Seite</a>
<?php endif; ?>
<main id="main" class="page">
  <?= $content ?>
</main>
<footer class="page-foot">
  <span>Keine Rechtsberatung – Rechtswirkung von Signaturen und Aufbewahrungsfristen ist durch Fachpersonen zu prüfen.</span>
</footer>
</div>
<script src="/assets/app.js" defer></script>
<script src="/assets/wizard.js" defer></script>
<script src="/assets/passkey.js" defer></script>
</body>
</html>
