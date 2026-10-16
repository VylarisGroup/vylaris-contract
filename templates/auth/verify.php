<?php $v->title = 'E-Mail bestätigen'; ?>
<h1 class="h2">E-Mail-Adresse bestätigen</h1>
<p class="muted">Bitte bestätigen Sie, dass diese Adresse Ihnen gehört.</p>
<?= $ui->form('/email/bestaetigen/' . rawurlencode($token), '<button class="btn btn-primary w-full" type="submit">Jetzt bestätigen</button>') ?>
