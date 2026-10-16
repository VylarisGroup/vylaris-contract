<?php

declare(strict_types=1);

// Wiederherstellungstest (Spezifikation Nr. 10): Sicherung -> Wiederherstellung in eine zweite Datenbank ->
// Konsistenzprüfung (Zeilenzahlen, Audit-Kette, Dokument-Hashes, Trigger). Läuft ausserhalb des Test-Runners, weil
// die Sicherung echte (nicht zurückgerollte) Daten braucht.
//
// Voraussetzung: zwei LEERE Datenbanken <DB_NAME aus .env.test>_src und _dst, auf die der Test-Benutzer alle Rechte hat.
//   php tests/restore_roundtrip.php
// Am Ende werden alle Tabellen in beiden Datenbanken wieder entfernt.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../src/autoload.php';
spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'Tests\\')) {
        $f = __DIR__ . '/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        is_file($f) && require $f;
    }
});

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Modules\Maintenance\BackupService;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\UploadValidator;
use Tests\Support\TestApp;

$c = Config::fromFile(getenv('TEST_ENV_FILE') ?: __DIR__ . '/../.env.test');
$connect = static fn (string $suffix): Database => Database::connect($c->get('DB_HOST', '127.0.0.1'), $c->int('DB_PORT', 3306), $c->get('DB_NAME') . $suffix, $c->get('DB_USER'), $c->get('DB_PASS'));
$src = $connect('_src');
$dst = $connect('_dst');

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? 'OK    ' : 'FEHLER') . ' ' . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};
$wipe = static function (Database $db): void {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
        $db->pdo()->exec('DROP TABLE IF EXISTS `' . $r['t'] . '`');
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
};

$tmp = sys_get_temp_dir() . '/vp-rt-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
try {
    $wipe($src);
    $wipe($dst);
    (new Migrator($src, __DIR__ . '/../database/migrations'))->migrate();

    // Synthetische Daten
    $app = new TestApp($src);
    $tenant = $app->fx->tenant('Roundtrip AG');
    $owner = $app->fx->user();
    $app->fx->member($tenant, $owner, 'contract_manager');
    $contract = $app->fx->contract($tenant, $owner);
    $versionId = $app->fx->approve($contract);
    $store = new DocumentStore($src, $app->ac, $app->audit, new UploadValidator(2_000_000), $tmp . '/live/private');
    $store->storeBytes($tenant, $contract, $versionId, "%PDF-1.4\nSynthetisch äöü \\ ' \" \n\0binär", 'test.pdf', 'application/pdf', 'attachment', $owner);
    for ($i = 0; $i < 5; $i++) {
        $app->audit->log('roundtrip.test', 'success', $tenant, $owner, 'contract', $contract, ['n' => $i, 'text' => "Zeile1\nZeile2 -- @@ ';"]);
    }
    $app->audit->anchor('roundtrip');
    $before = (array) $src->one('SELECT (SELECT COUNT(*) FROM contracts) c, (SELECT COUNT(*) FROM contract_versions) v, (SELECT COUNT(*) FROM documents) d, (SELECT COUNT(*) FROM audit_events) a, (SELECT COUNT(*) FROM users) u');

    $svc = new BackupService($src, $tmp . '/live');
    $arch = $svc->create($tmp . '/b', 'rt-passphrase-1');
    $check('Archiv verschlüsselt erzeugt', $arch['encrypted'] && is_file($arch['path']));
    $check('Archiv verifiziert', $svc->verify($arch['path'], 'rt-passphrase-1')['ok']);
    $check('falsche Passphrase abgelehnt', !$svc->verify($arch['path'], 'falsch')['ok']);

    $res = (new BackupService($dst, $tmp . '/restore'))->restore($arch['path'], 'rt-passphrase-1', $dst, $tmp . '/restore');
    $check('Audit-Kette nach Wiederherstellung intakt (' . $res['audit_checked'] . ' Einträge)', $res['audit_ok']);
    $check('alle Dokument-Hashes stimmen', $res['file_mismatch'] === 0 && $res['files'] === 1);
    $after = (array) $dst->one('SELECT (SELECT COUNT(*) FROM contracts) c, (SELECT COUNT(*) FROM contract_versions) v, (SELECT COUNT(*) FROM documents) d, (SELECT COUNT(*) FROM audit_events) a, (SELECT COUNT(*) FROM users) u');
    $check('Zeilenzahlen identisch', $before === $after);
    $h1 = $src->value('SELECT entry_hash FROM audit_events ORDER BY seq DESC LIMIT 1');
    $h2 = $dst->value('SELECT entry_hash FROM audit_events ORDER BY seq DESC LIMIT 1');
    $check('Kettenkopf identisch', $h1 === $h2 && $h1 !== null);
    $v1 = $src->value('SELECT content_hash FROM contract_versions WHERE state = "locked" LIMIT 1');
    $check('gesperrte Version unverändert', $v1 === $dst->value('SELECT content_hash FROM contract_versions WHERE state = "locked" LIMIT 1'));
    $t = (int) $dst->value("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg\\_%'");
    $check('16 Trigger wiederhergestellt', $t === 16);
    $blocked = false;
    try {
        $dst->run("UPDATE audit_events SET action = 'manipuliert' WHERE seq = 1");
    } catch (\Throwable) {
        $blocked = true;
    }
    $check('Protokoll ist nach Wiederherstellung weiterhin gegen Änderung geschützt', $blocked);
    $nonEmpty = false;
    try {
        (new BackupService($dst, $tmp . '/r2'))->restore($arch['path'], 'rt-passphrase-1', $dst, $tmp . '/r2');
    } catch (\RuntimeException) {
        $nonEmpty = true;
    }
    $check('Wiederherstellung in nicht leere Datenbank wird verweigert', $nonEmpty);
} finally {
    $wipe($src);
    $wipe($dst);
    BackupService::rrmdir($tmp);
}
echo $fail === 0 ? "Wiederherstellungstest bestanden.\n" : "Wiederherstellungstest FEHLGESCHLAGEN ({$fail}).\n";
exit($fail === 0 ? 0 : 1);
