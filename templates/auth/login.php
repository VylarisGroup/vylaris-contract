<?php $v->title = 'Anmelden'; ?>
<h1 class="h2">Anmelden</h1>
<?php if (!empty($notice)): ?><div class="alert alert-info" role="status"><?= e($notice) ?></div><?php endif; ?>
<?= $ui->form('/anmelden', $ui->field('email', 'E-Mail-Adresse', ['type' => 'email', 'required' => true, 'autocomplete' => 'username'])
  . $ui->field('password', 'Passwort', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
  . '<input type="hidden" name="weiter" value="' . e($next) . '">'
  . '<button class="btn btn-primary w-full" type="submit"><i class="bx bx-log-in" aria-hidden="true"></i> Anmelden</button>') ?>
<p class="small"><a href="/passwort/vergessen">Passwort vergessen?</a></p>
<div class="passkey-box" data-passkey-login data-options-url="/anmelden/passkey/optionen" data-login-url="/anmelden/passkey" data-csrf="<?= e($csrf) ?>" data-next="<?= e($next) ?>" hidden>
  <div class="divider"><span>oder</span></div>
  <button class="btn btn-secondary w-full" type="button" data-passkey-button><i class="bx bx-fingerprint" aria-hidden="true"></i> Mit Passkey anmelden</button>
  <p class="field-error" role="alert" data-passkey-error hidden></p>
</div>

<?php if (!empty($ssoAvailable)): ?>
<div class="divider"><span>oder</span></div>
<details class="sso-box">
  <summary class="btn btn-secondary w-full"><i class="bx bx-buildings" aria-hidden="true"></i> Mit SSO anmelden</summary>
  <?= $ui->form('/anmelden/sso', $ui->field('hint', 'Kürzel des Mandanten oder E-Mail-Adresse', ['required' => true, 'maxlength' => 190, 'autocomplete' => 'off', 'help' => 'Das Kürzel erhalten Sie von Ihrer Administration.'])
    . '<button class="btn btn-primary w-full" type="submit">Weiter</button>', false, 'stack mt') ?>
</details>
<?php endif; ?>
