<?php

declare(strict_types=1);

// Wiederherstellung / Wiederherstellungstest:
//   php bin/restore.php <archiv> --verify
//        prüft Entschlüsselung und alle Hashes, ändert nichts
//   php bin/restore.php <archiv> --db=NAME [--storage=/pfad] [--user=U --pass=P --host=H --port=3306]
//        stellt in eine LEERE Datenbank und ein Zielverzeichnis wieder her und prüft danach Audit-Kette und Dokument-Hashes
// Passphrase: Umgebungsvariable BACKUP_PASSPHRASE oder .env. Die Live-Datenbank wird nie überschrieben:
// für einen Echtbetrieb-Restore zuerst in eine neue Datenbank einspielen, prüfen, dann in .env umstellen.
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
$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Verwendung: php bin/restore.php <archiv> --verify | --db=NAME [--storage=PFAD]\n");
    exit(2);
}
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--')) {
        $kv = explode('=', substr($a, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    }
}
$envFile = getenv('APP_ENV_FILE') ?: $root . '/.env';
$config = is_file($envFile) ? Config::fromFile($envFile) : new Config([]);
$pass = getenv('BACKUP_PASSPHRASE') ?: $config->get('BACKUP_PASSPHRASE', '');
$pass = $pass === '' ? null : $pass;

if (isset($opts['verify'])) {
    $v = (new BackupService(null, ''))->verify($file, $pass);
    echo $v['ok'] ? "Archiv in Ordnung ({$v['entries']} Einträge, alle Hashes stimmen).\n" : "FEHLER: " . implode("\n", $v['errors']) . "\n";
    exit($v['ok'] ? 0 : 1);
}
if (!isset($opts['db'])) {
    fwrite(STDERR, "--verify oder --db=NAME angeben.\n");
    exit(2);
}
$target = (string) $opts['db'];
if ($target === $config->get('DB_NAME', '')) {
    fwrite(STDERR, "Ziel ist die Live-Datenbank. Bitte eine neue, leere Datenbank verwenden.\n");
    exit(2);
}
$storage = (string) ($opts['storage'] ?? $root . '/storage/restore-test');
if (realpath($storage) !== false && realpath($storage) === realpath($config->get('STORAGE_PATH', $root . '/storage'))) {
    fwrite(STDERR, "Zielverzeichnis ist der Live-Speicher. Bitte ein anderes Verzeichnis verwenden.\n");
    exit(2);
}
@mkdir($storage, 0750, true);
$tdb = Database::connect((string) ($opts['host'] ?? $config->get('DB_HOST', '127.0.0.1')), (int) ($opts['port'] ?? $config->int('DB_PORT', 3306)), $target, (string) ($opts['user'] ?? $config->get('DB_USER')), (string) ($opts['pass'] ?? $config->get('DB_PASS')));
$r = (new BackupService($tdb, $storage))->restore($file, $pass, $tdb, $storage);
echo "Wiederhergestellt: {$r['statements']} SQL-Anweisungen, {$r['files']} Dateien.\n";
echo 'Audit-Kette: ' . ($r['audit_ok'] ? "OK ({$r['audit_checked']} Einträge)" : 'FEHLER') . "\n";
echo 'Dokument-Hashes: ' . ($r['file_mismatch'] === 0 ? 'alle OK' : "{$r['file_mismatch']} Abweichungen") . "\n";
exit($r['audit_ok'] && $r['file_mismatch'] === 0 ? 0 : 1);
