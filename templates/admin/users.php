<?php $v->title = 'Benutzer'; ?>
<section class="stack">
  <p class="muted small"><a href="/verwaltung">Verwaltung</a></p>
  <h1 class="h1">Benutzer &amp; Rollen</h1>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Rolle</th><th scope="col">Status</th><th scope="col">2. Faktor</th><th scope="col">Letzte Anmeldung</th><th scope="col">Aktionen</th></tr></thead>
    <tbody><?php foreach ($members as $m): $uid = (int) $m['id']; ?>
      <tr><td><?= e($m['display_name']) ?><br><span class="muted small"><?= e($m['email']) ?></span></td>
        <td><form method="post" action="/verwaltung/benutzer/<?= $uid ?>/rolle" class="row-gap"><?= $ui->csrf() ?><label class="sr-only" for="r<?= $uid ?>">Rolle</label>
          <select class="input input-sm" id="r<?= $uid ?>" name="role"><?php foreach ($roles as $code => $label): ?><option value="<?= e($code) ?>"<?= str_contains((string) $m['roles'], $code) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <button class="btn btn-secondary btn-sm" type="submit">Setzen</button></form></td>
        <td><?= $m['user_status'] === 'pending' ? '<span class="badge badge-warn">Einladung offen</span> ' : '' ?><?= $m['membership_status'] === 'active' ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge badge-bad">' . e($m['membership_status']) . '</span>' ?></td>
        <td><?= $m['mfa_enabled_at'] ? '<span class="badge badge-ok">TOTP</span> ' : '' ?><?= (int) $m['passkey_count'] > 0 ? '<span class="badge badge-brand">Passkey</span>' : '' ?><?= !$m['mfa_enabled_at'] && (int) $m['passkey_count'] === 0 ? '<span class="muted">–</span>' : '' ?></td><td><?= e(fdt($m['last_login_at'])) ?></td>
        <td><div class="row-gap">
          <?php if ($m['membership_status'] === 'active'): ?><?= $ui->action('/verwaltung/benutzer/' . $uid . '/status', 'Sperren', 'bx-block', 'btn btn-ghost btn-sm', 'Zugang dieses Benutzers sperren?', ['status' => 'suspended']) ?>
          <?php else: ?><?= $ui->action('/verwaltung/benutzer/' . $uid . '/status', 'Aktivieren', 'bx-check', 'btn btn-ghost btn-sm', null, ['status' => 'active']) ?><?php endif; ?>
          <?php if ($m['user_status'] === 'pending'): ?><?= $ui->action('/verwaltung/benutzer/' . $uid . '/einladung', 'Einladung erneut', 'bx-mail-send', 'btn btn-ghost btn-sm') ?><?php endif; ?>
          <?php if ($m['mfa_enabled_at'] || (int) $m['passkey_count'] > 0): ?><?= $ui->action('/verwaltung/benutzer/' . $uid . '/mfa-zuruecksetzen', 'Zweiten Faktor zurücksetzen', 'bx-reset', 'btn btn-ghost btn-sm', 'TOTP und alle Passkeys dieses Benutzers entfernen? Er kann sie danach neu einrichten.') ?><?php endif; ?>
        </div></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="card"><h2 class="h3">Benutzer einladen</h2>
    <form method="post" action="/verwaltung/benutzer" class="stack" data-wizard="auto"><?= $ui->csrf() ?>
      <div class="wz-step stack" data-title="Person" data-intro="Wer soll eingeladen werden? Die Person erhält eine E-Mail mit einem Link zum Festlegen des Passworts.">
      <div class="grid-2"><?= $ui->field('display_name', 'Name', ['required' => true, 'maxlength' => 190]) ?><?= $ui->field('email', 'E-Mail', ['type' => 'email', 'required' => true, 'maxlength' => 190]) ?></div></div>
      <div class="wz-step stack" data-title="Rolle und Abteilungen" data-intro="Was darf die Person? Eine Rollenänderung beendet bestehende Sitzungen. Abteilungen sind optional.">
      <?= $ui->field('role', 'Rolle', ['type' => 'select', 'options' => $roles, 'value' => 'reader']) ?>
      <?php if ($departments !== []): ?><fieldset class="fieldset"><legend>Abteilungen</legend><?php foreach ($departments as $d): ?><label class="check"><input type="checkbox" name="departments[]" value="<?= (int) $d['id'] ?>"> <span><?= e($d['name']) ?></span></label><?php endforeach; ?></fieldset><?php endif; ?></div>
      <div class="wz-actions"><button class="btn btn-primary" type="submit"><i class="bx bx-mail-send" aria-hidden="true"></i> Einladung senden</button></div></form>
    <p class="muted small mt">Rollen: Mandantenadministration (alle Rechte im Mandanten), Vertragsverwaltung (Verträge, Vorlagen, Fristen), Genehmigungsstelle (Freigaben), Lesender Zugriff (nur zugewiesene Verträge). Eine Rollenänderung beendet bestehende Sitzungen.</p></div>
</section>
