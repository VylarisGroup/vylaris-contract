<nav class="subnav" aria-label="Konto">
  <?php foreach ([['/konto', 'Profil', 'bx-user'], ['/konto/passwort', 'Passwort', 'bx-key'], ['/konto/mfa', 'Zweiter Faktor', 'bx-shield-alt-2'], ['/konto/passkeys', 'Passkeys', 'bx-fingerprint'], ['/konto/sitzungen', 'Sitzungen', 'bx-devices']] as [$href, $label, $icon]): ?>
    <a href="<?= e($href) ?>" class="subnav-link<?= $current === $href ? ' is-active' : '' ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>><i class="bx <?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
