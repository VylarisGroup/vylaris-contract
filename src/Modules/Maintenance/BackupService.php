<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Core\Database;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Sicherung und Wiederherstellung ohne externe Programme (kein mysqldump nötig, Plesk-tauglich).
 *
 * Archiv (ZIP): db.sql (Struktur, Daten, Trigger), files/<Speicherschlüssel-Pfad> (Dokumente), manifest.json
 * (SHA-256 je Eintrag). Optional wird das ganze Archiv mit einer Passphrase verschlüsselt
 * (libsodium secretstream, Schlüssel via Argon2id). Ohne Passphrase enthält die Datei Klartext-Personendaten
 * und muss entsprechend geschützt werden.
 */
final class BackupService
{
    private const MARK = "\n-- @@\n";
    private const CHUNK = 1048576;

    public function __construct(private readonly ?Database $db, private readonly string $storageRoot)
    {
    }

    /** @return array{path:string,bytes:int,tables:int,files:int,encrypted:bool} */
    public function create(string $targetDir, ?string $passphrase): array
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true)) {
            throw new RuntimeException('Sicherungsverzeichnis kann nicht angelegt werden.');
        }
        $stamp = gmdate('Ymd-His');
        $zipPath = $targetDir . "/sicherung-{$stamp}.zip";
        $sqlPath = $targetDir . "/.db-{$stamp}.tmp";
        $tables = $this->dumpSql($sqlPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Archiv kann nicht angelegt werden.');
        }
        $manifest = ['format' => 1, 'created_at' => gmdate('c'), 'entries' => [], 'migrations' => array_keys((new \App\Core\Migrator($this->db ?? throw new RuntimeException('Keine Datenbank.'), dirname(__DIR__, 3) . '/database/migrations'))->applied())];
        $zip->addFile($sqlPath, 'db.sql');
        $manifest['entries']['db.sql'] = hash_file('sha256', $sqlPath);
        $files = 0;
        $priv = $this->storageRoot . '/private';
        if (is_dir($priv)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($priv, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink() && !str_starts_with($f->getFilename(), '.')) {
                    $rel = 'files/' . ltrim(substr($f->getPathname(), strlen($priv)), '/');
                    $zip->addFile($f->getPathname(), $rel);
                    $manifest['entries'][$rel] = hash_file('sha256', $f->getPathname());
                    $files++;
                }
            }
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        @unlink($sqlPath);
        @chmod($zipPath, 0600);

        $final = $zipPath;
        $enc = $passphrase !== null && $passphrase !== '';
        if ($enc) {
            $final = $zipPath . '.enc';
            self::encryptFile($zipPath, $final, $passphrase);
            @unlink($zipPath);
            @chmod($final, 0600);
        }
        return ['path' => $final, 'bytes' => (int) filesize($final), 'tables' => $tables, 'files' => $files, 'encrypted' => $enc];
    }

    /** Löscht die ältesten Sicherungen und behält $keep Stück. */
    public function prune(string $dir, int $keep): int
    {
        $list = glob($dir . '/sicherung-*.zip*') ?: [];
        sort($list);
        $del = 0;
        foreach (array_slice($list, 0, max(0, count($list) - $keep)) as $f) {
            if (@unlink($f)) {
                $del++;
            }
        }
        return $del;
    }

    /**
     * Prüft ein Archiv ohne Wiederherstellung: Entschlüsselung, Zip, alle Hashes.
     * @return array{ok:bool,errors:list<string>,entries:int,workdir:string|null}
     */
    public function verify(string $file, ?string $passphrase, bool $keepExtracted = false): array
    {
        $errors = [];
        $tmp = sys_get_temp_dir() . '/vp-verify-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        $zipFile = $file;
        if (str_ends_with($file, '.enc')) {
            if ($passphrase === null || $passphrase === '') {
                return ['ok' => false, 'errors' => ['Archiv ist verschlüsselt, Passphrase fehlt.'], 'entries' => 0, 'workdir' => null];
            }
            $zipFile = $tmp . '/a.zip';
            try {
                self::decryptFile($file, $zipFile, $passphrase);
            } catch (\Throwable $e) {
                self::rrmdir($tmp);
                return ['ok' => false, 'errors' => [$e->getMessage()], 'entries' => 0, 'workdir' => null];
            }
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            self::rrmdir($tmp);
            return ['ok' => false, 'errors' => ['Archiv nicht lesbar.'], 'entries' => 0, 'workdir' => null];
        }
        $mf = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($mf) || !isset($mf['entries'])) {
            $zip->close();
            self::rrmdir($tmp);
            return ['ok' => false, 'errors' => ['Manifest fehlt oder ist defekt.'], 'entries' => 0, 'workdir' => null];
        }
        $out = $tmp . '/x';
        mkdir($out, 0700);
        foreach ($mf['entries'] as $name => $hash) {
            if (str_contains((string) $name, '..') || str_starts_with((string) $name, '/')) {
                $errors[] = "Unzulässiger Pfad: {$name}";
                continue;
            }
            $stream = $zip->getStream((string) $name);
            if ($stream === false) {
                $errors[] = "Eintrag fehlt: {$name}";
                continue;
            }
            $dest = $out . '/' . $name;
            @mkdir(dirname($dest), 0700, true);
            $h = hash_init('sha256');
            $fh = fopen($dest, 'wb');
            while (!feof($stream)) {
                $buf = fread($stream, self::CHUNK);
                if ($buf === false) {
                    break;
                }
                hash_update($h, $buf);
                fwrite($fh, $buf);
            }
            fclose($fh);
            fclose($stream);
            if (!hash_equals((string) $hash, hash_final($h))) {
                $errors[] = "Hash stimmt nicht: {$name}";
            }
        }
        $zip->close();
        $entries = count($mf['entries']);
        if ($errors !== [] || !$keepExtracted) {
            self::rrmdir($tmp);
            return ['ok' => $errors === [], 'errors' => $errors, 'entries' => $entries, 'workdir' => null];
        }
        return ['ok' => true, 'errors' => [], 'entries' => $entries, 'workdir' => $tmp];
    }

    /**
     * Stellt in eine LEERE Datenbank und ein Zielverzeichnis wieder her (nie in die Live-Daten, ausser der
     * Betreiber zeigt bewusst darauf). Gibt Prüfergebnisse zurück.
     * @return array{statements:int,files:int,audit_ok:bool,audit_checked:int,file_mismatch:int,migrations_ok:bool}
     */
    public function restore(string $file, ?string $passphrase, Database $targetDb, string $targetStorage): array
    {
        $existing = (int) $targetDb->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        if ($existing > 0) {
            throw new RuntimeException('Zieldatenbank ist nicht leer. Wiederherstellung nur in eine leere Datenbank.');
        }
        $v = $this->verify($file, $passphrase, true);
        if (!$v['ok'] || $v['workdir'] === null) {
            throw new RuntimeException('Archiv ungültig: ' . implode('; ', $v['errors']));
        }
        $work = $v['workdir'] . '/x';
        try {
            $pdo = $targetDb->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec("SET sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            $n = 0;
            $buf = '';
            $fh = fopen($work . '/db.sql', 'rb');
            while (($line = fgets($fh)) !== false) {
                if (rtrim($line, "\r\n") === '-- @@') {
                    $stmt = trim($buf);
                    $buf = '';
                    if ($stmt !== '') {
                        $pdo->exec($stmt);
                        $n++;
                    }
                    continue;
                }
                $buf .= $line;
            }
            fclose($fh);
            if (trim($buf) !== '') {
                $pdo->exec(trim($buf));
                $n++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

            $files = 0;
            $dest = rtrim($targetStorage, '/') . '/private';
            if (is_dir($work . '/files')) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work . '/files', \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $rel = substr($f->getPathname(), strlen($work . '/files'));
                    @mkdir(dirname($dest . $rel), 0750, true);
                    copy($f->getPathname(), $dest . $rel);
                    @chmod($dest . $rel, 0640);
                    $files++;
                }
            }
            // Prüfungen: Audit-Kette und Dokument-Hashes der wiederhergestellten Daten.
            $audit = (new \App\Modules\Audit\AuditLogger($targetDb, 'restore'))->verify();
            $bad = 0;
            foreach ($targetDb->all('SELECT storage_key, sha256 FROM documents WHERE deleted_at IS NULL') as $d) {
                $p = $dest . '/' . substr((string) $d['storage_key'], 0, 2) . '/' . substr((string) $d['storage_key'], 2, 2) . '/' . $d['storage_key'];
                if (!is_file($p) || !hash_equals((string) $d['sha256'], (string) hash_file('sha256', $p))) {
                    $bad++;
                }
            }
            $mig = (int) $targetDb->value('SELECT COUNT(*) FROM schema_migrations');
            return ['statements' => $n, 'files' => $files, 'audit_ok' => (bool) $audit['ok'], 'audit_checked' => (int) $audit['checked'], 'file_mismatch' => $bad, 'migrations_ok' => $mig > 0];
        } finally {
            self::rrmdir($v['workdir']);
        }
    }

    private function dumpSql(string $path): int
    {
        $pdo = $this->db?->pdo() ?? throw new RuntimeException("Keine Datenbank.");
        $fh = fopen($path, 'wb');
        $w = static function (string $s) use ($fh): void {
            fwrite($fh, $s . self::MARK);
        };
        $w('SET NAMES utf8mb4');
        $w('SET FOREIGN_KEY_CHECKS=0');
        $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $r) {
            $tables[] = (string) $r[0];
        }
        // Konsistenter Schnappschuss für alle Tabellen.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            foreach ($tables as $t) {
                $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM);
                $w((string) $create[1]);
            }
            foreach ($tables as $t) {
                $q = '`' . str_replace('`', '``', $t) . '`';
                $st = $pdo->query("SELECT * FROM {$q}", PDO::FETCH_NUM);
                $batch = [];
                $cols = null;
                $meta = [];
                while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    if ($cols === null) {
                        for ($i = 0; $i < $st->columnCount(); $i++) {
                            $meta[] = '`' . str_replace('`', '``', (string) $st->getColumnMeta($i)['name']) . '`';
                        }
                        $cols = implode(',', $meta);
                    }
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $this->lit($v);
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';
                    if (count($batch) >= 200) {
                        $w("INSERT INTO {$q} ({$cols}) VALUES " . implode(',', $batch));
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $w("INSERT INTO {$q} ({$cols}) VALUES " . implode(',', $batch));
                }
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            fclose($fh);
            @unlink($path);
            throw $e;
        }
        // Trigger zuletzt, damit sie beim Einspielen der Daten nicht stören.
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $c = $pdo->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', (string) $tr['Trigger']) . '`')->fetch(PDO::FETCH_ASSOC);
            $sql = (string) ($c['SQL Original Statement'] ?? '');
            if ($sql !== '') {
                $w(preg_replace('/^CREATE\s+DEFINER=\S+\s+TRIGGER/i', 'CREATE TRIGGER', $sql) ?? $sql);
            }
        }
        $w('SET FOREIGN_KEY_CHECKS=1');
        fclose($fh);
        return count($tables);
    }

    private function lit(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $s = (string) $v;
        if ($s === '') {
            return "''";
        }
        if (preg_match('/^-?[1-9][0-9]{0,17}$|^0$/', $s) === 1) {
            return $s;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\\']|[^\x20-\x7e]/', $s) === 1) {
            return 'CONVERT(0x' . bin2hex($s) . ' USING utf8mb4)';
        }
        return "'" . $s . "'";
    }

    public static function encryptFile(string $in, string $out, string $pass): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $pass, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $r = fopen($in, 'rb');
        $o = fopen($out, 'wb');
        fwrite($o, "VPENC1\n" . $salt . $header);
        while (!feof($r)) {
            $chunk = (string) fread($r, self::CHUNK);
            $last = feof($r);
            fwrite($o, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
        }
        fclose($r);
        fclose($o);
        sodium_memzero($key);
    }

    public static function decryptFile(string $in, string $out, string $pass): void
    {
        $r = fopen($in, 'rb');
        if (fread($r, 7) !== "VPENC1\n") {
            throw new RuntimeException('Kein verschlüsseltes Vertragsplattform-Archiv.');
        }
        $salt = (string) fread($r, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = (string) fread($r, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $pass, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $o = fopen($out, 'wb');
        $final = false;
        while (!feof($r)) {
            $chunk = (string) fread($r, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($chunk === '') {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($res === false) {
                throw new RuntimeException('Entschlüsselung fehlgeschlagen (falsche Passphrase oder beschädigte Datei).');
            }
            fwrite($o, $res[0]);
            $final = $res[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($r);
        fclose($o);
        if (!$final) {
            throw new RuntimeException('Archiv unvollständig (Endmarke fehlt).');
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
