<?php

declare(strict_types=1);

// Notfallwerkzeug (Kommandozeile): php bin/repair.php [--rollback]
//  ohne Option: setzt Dateirechte der Programmverzeichnisse zurück (Ordner 0755, Dateien 0644) und beendet den Wartungsmodus.
//  --rollback : stellt den Programmstand vor dem letzten Update wieder her.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Modules\Audit\AuditLogger;
use App\Modules\Maintenance\UpdateService;

date_default_timezone_set('UTC');
$root = dirname(__DIR__);
$config = Config::fromFile(getenv('APP_ENV_FILE') ?: $root . '/.env');
$db = Database::connect($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$storage = $config->get('STORAGE_PATH', $root . '/storage');
$svc = new UpdateService($db, new AuditLogger($db, hash('sha256', 'audit-ip|' . $config->get('APP_KEY'))), $root, $storage);
try {
    if (in_array('--rollback', $argv, true)) {
        echo $svc->rollbackLast(), "\n";
    } else {
        echo $svc->repairPermissions() . " Rechte korrigiert.\n";
        $svc->setMaintenance(false);
        echo "Wartungsmodus beendet.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
