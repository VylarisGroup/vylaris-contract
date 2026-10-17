<?php /** Parteienblock des Vertragsformulars (Teil von form.php) */ ?>
    <fieldset class="fieldset"><legend>Vertragsparteien</legend>
      <?php if ($counterparties === []): ?><p class="muted">Noch keine verbundenen Gegenparteien. <a href="/gegenparteien#verbinden">Mit einem Mandanten verbinden</a> (Mandanten-Code).</p><?php endif; ?>
      <?php for ($i = 0; $i < $rows; $i++): $pp = $parties[$i] ?? null; ?>
        <div class="grid-3 party-row">
          <div class="field"><label class="label" for="pcp<?= $i ?>">Partei <?= $i + 1 ?></label>
            <select class="input" id="pcp<?= $i ?>" name="party_cp[]"><option value="">–</option>
              <?php foreach ($counterparties as $cp): ?><option value="<?= (int) $cp['id'] ?>"<?= $pp && (int) $pp['counterparty_id'] === (int) $cp['id'] ? ' selected' : '' ?>><?= e($cp['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label class="label" for="prole<?= $i ?>">Rolle (z. B. Auftraggeber)</label><input class="input" id="prole<?= $i ?>" name="party_role[]" maxlength="80" value="<?= e($pp['role_label'] ?? '') ?>"></div>
          <div class="field"><label class="check"><input type="checkbox" name="party_sign[<?= $i ?>]" value="1"<?= $pp && (int) $pp['must_sign'] === 1 ? ' checked' : '' ?>> <span>unterzeichnet</span></label></div>
        </div>
      <?php endfor; ?>
      <?php if ($ui->error('parties.0')): ?><p class="field-error" role="alert"><?= e($ui->error('parties.0')) ?></p><?php endif; ?>
    </fieldset>
