<?php

declare(strict_types=1);

// Plesk-Cron: php /pfad/zur/app/bin/cron.php   (empfohlen: alle 5 Minuten)
// Läuft kurz und beendet sich; Doppelläufe werden per Datenbank-Sperre verhindert.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Web\Services;

date_default_timezone_set('UTC');
$root = dirname(__DIR__);
$envFile = getenv('APP_ENV_FILE') ?: $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "Keine .env gefunden - Installation noch nicht abgeschlossen.\n");
    exit(2);
}
$config = Config::fromFile($envFile);
$db = Database::connect($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$s = new Services($config, $db, $root);
$s->audit->setContext(null, 'cron-' . bin2hex(random_bytes(4)));

$steps = [
    'Fristen abgleichen' => static fn (): string => json_encode($s->deadlines->syncAllDerived(), JSON_UNESCAPED_UNICODE),
    'Fristen melden' => static fn (): string => json_encode($s->deadlines->scanAndNotify(), JSON_UNESCAPED_UNICODE),
    'Verträge ablaufen lassen' => static fn (): string => 'abgelaufen: ' . $s->deadlines->expireContracts(),
    'E-Mail-Warteschlange' => static fn (): string => json_encode($s->notifications->processEmailQueue(50), JSON_UNESCAPED_UNICODE),
    'Signaturstatus' => static fn (): string => json_encode($s->signatures->refreshOpen(50), JSON_UNESCAPED_UNICODE),
];

$exit = 0;
$res = $s->cron->run('tick', static function () use ($s, $steps, &$exit): string {
    $parts = [];
    foreach ($steps as $label => $fn) {
        $r = $s->cron->run('job:' . $label, $fn, 600);
        $parts[] = $label . '=' . $r['status'];
        if ($r['status'] === 'failure') {
            $exit = 1;
            fwrite(STDERR, "FEHLER bei {$label}: {$r['message']}\n");
        }
    }
    return implode('; ', $parts);
}, 1200);

// Abgelaufene Sitzungsdateien entfernen (absolute Sitzungsdauer 12 Std. + Reserve).
$deleted = 0;
foreach (glob(($config->get('STORAGE_PATH', $root . '/storage')) . '/sessions/sess_*') ?: [] as $f) {
    if (is_file($f) && filemtime($f) < time() - 13 * 3600 && @unlink($f)) {
        $deleted++;
    }
}

// Tägliche Aufräumarbeiten (höchstens einmal pro Tag).
$daily = $db->one("SELECT last_finished_at FROM maintenance_jobs WHERE job_name = 'daily' AND last_status = 'success'");
if ($daily === null || strtotime((string) $daily['last_finished_at'] . ' UTC') < time() - 82800) {
    $s->cron->run('daily', static function () use ($s, $db): string {
        $n = $s->notifications->purgeOld(180);
        $l = $s->limiter->purgeOlderThan(86400 * 2);
        $t = $db->execute('DELETE FROM auth_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 7) . '.000000']);
        $a = $s->audit->anchor('cron-daily');
        return "Benachrichtigungen={$n}, Rate-Limit={$l}, Tokens={$t}, Anker=" . ($a['seq'] ?? '-');
    }, 1800);
}

echo '[' . gmdate('c') . '] ' . $res['status'] . ' - ' . $res['message'] . ($deleted > 0 ? "; Sitzungsdateien entfernt={$deleted}" : '') . "\n";
exit($res['status'] === 'failure' ? 1 : $exit);
