<?php

declare(strict_types=1);

// Sicherung: php bin/backup.php [--keep=14] [--dir=/pfad]
// Passphrase (optional, empfohlen) aus .env BACKUP_PASSPHRASE. Ziel: storage/backups (ausserhalb des Webroots).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Modules\Maintenance\BackupService;

date_default_timezone_set('UTC');
$root = dirname(__DIR__);
$config = Config::fromFile(getenv('APP_ENV_FILE') ?: $root . '/.env');
$db = Database::connect($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$storage = $config->get('STORAGE_PATH', $root . '/storage');
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $kv = explode('=', substr($a, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    }
}
$dir = (string) ($opts['dir'] ?? $storage . '/backups');
$keep = (int) ($opts['keep'] ?? $config->int('BACKUP_KEEP', 14));
$pass = $config->get('BACKUP_PASSPHRASE', '');

$svc = new BackupService($db, $storage);
$r = $svc->create($dir, $pass === '' ? null : $pass);
$pruned = $svc->prune($dir, max(1, $keep));
$v = $svc->verify($r['path'], $pass === '' ? null : $pass);
echo "Sicherung: {$r['path']} ({$r['bytes']} Bytes, {$r['tables']} Tabellen, {$r['files']} Dateien, " . ($r['encrypted'] ? 'verschlüsselt' : 'UNVERSCHLÜSSELT') . ")\n";
echo 'Prüfung des Archivs: ' . ($v['ok'] ? 'OK (' . $v['entries'] . ' Einträge, Hashes stimmen)' : 'FEHLER: ' . implode('; ', $v['errors'])) . "\n";
if ($pruned > 0) {
    echo "Alte Sicherungen entfernt: {$pruned}\n";
}
if ($pass === '') {
    fwrite(STDERR, "HINWEIS: BACKUP_PASSPHRASE ist nicht gesetzt - die Sicherung enthält Klartext-Personendaten.\n");
}
exit($v['ok'] ? 0 : 1);
