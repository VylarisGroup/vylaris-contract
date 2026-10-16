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
$envConfig = $config;
$config = \App\Modules\Maintenance\SettingsService::overlay($config, $db);
$s = new Services($config, $db, $root, $envConfig);
$s->audit->setContext(null, 'cron-' . bin2hex(random_bytes(4)));

$r = (new \App\Modules\Maintenance\CronTasks($s))->run();
foreach ($r['failed'] as $f) {
    fwrite(STDERR, "FEHLER bei {$f}\n");
}
echo '[' . gmdate('c') . '] ' . $r['status'] . ' - ' . $r['message'] . ($r['sessions_removed'] > 0 ? "; Sitzungsdateien entfernt={$r['sessions_removed']}" : '') . "\n";
exit($r['status'] === 'failure' || $r['failed'] !== [] ? 1 : 0);
