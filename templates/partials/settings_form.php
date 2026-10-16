<?php
/** Formular für eine Einstellungsgruppe. Erwartet: $group, $items, optional $phpLimits. */
ob_start();
foreach ($items as $k => $it) {
    if ($it['group'] !== $group) {
        continue;
    }
    $help = (string) ($it['help'] ?? '');
    if ($k === 'MAX_UPLOAD_MB' && isset($phpLimits)) {
        $help .= ' Aktuell: upload_max_filesize ' . $phpLimits['upload'] . ', post_max_size ' . $phpLimits['post'] . '.';
    }
    $isSecret = (bool) ($it['secret'] ?? false);
    $opts = ['type' => $isSecret ? 'password' : $it['type'], 'value' => $it['value'] !== '' ? $it['value'] : ($it['default'] ?? ''),
        'help' => trim($help . ' Quelle: ' . ($isSecret && $it['isSet'] ? 'gesetzt (' . $it['source'] . ')' : $it['source']) . '.'), 'autocomplete' => 'off'];
    if ($it['type'] === 'select') {
        $opts['options'] = $it['options'];
    }
    if ($isSecret) {
        $opts['placeholder'] = $it['isSet'] ? '•••••••• (unverändert)' : '';
    }
    echo $ui->field($k, $it['label'], $opts);
    if ($it['inDb']) {
        echo '<div class="field"><label class="check"><input type="checkbox" name="reset[' . e($k) . ']" value="1"> <span>»' . e($it['label']) . '« auf den .env-Wert zurücksetzen</span></label></div>';
    }
}
$inner = ob_get_clean();
echo $ui->form('/system/einstellungen/' . $group, $inner . '<div><button type="submit" class="btn btn-primary"><i class="bx bx-save" aria-hidden="true"></i> Speichern</button></div>');
