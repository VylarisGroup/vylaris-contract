<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use ZipArchive;

/**
 * Aktualisierung aus dem fest hinterlegten GitHub-Repository (nur Release-Pakete, nur github.com-Hosts).
 *
 * Ablauf (nur Systemadministration): neueste Release ermitteln → Paket laden (Grössenlimit) → Sicherung (DB + Dokumente)
 * → Code-Schnappschuss für den Rückweg → Wartungsmodus → Paket in Staging entpacken (Pfad-/Symlink-Prüfung, nur
 * erlaubte Verzeichnisse, PHP-Syntaxprüfung aller Dateien) → Verzeichnisse austauschen → Migrationen →
 * bei Fehler Code zurückspielen. `.env`, `storage/` und `SETUP-SCHLUESSEL.txt` werden nie angefasst.
 *
 * ACHTUNG (bewusste Vorgabe des Betreibers): Es gibt keine kryptographische Signaturprüfung des Pakets. Wer das
 * GitHub-Konto/Repository kontrolliert, kann damit Code auf diesem Server ausführen. Die SHA-256 des geladenen
 * Pakets wird protokolliert.
 */
final class UpdateService
{
    public const REPO = 'VylarisGroup/vylaris-contract';
    private const API_LATEST = 'https://api.github.com/repos/VylarisGroup/vylaris-contract/releases/latest';
    private const HOSTS = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    private const DIRS = ['src', 'templates', 'public', 'bin', 'database', 'docs', 'tests'];
    private const FILES = ['VERSION', 'README.md', 'composer.json', '.env.example'];
    private const MAX_DOWNLOAD = 80_000_000;
    private const MAX_UNPACKED = 250_000_000;
    private const MAX_ENTRIES = 20000;

    /** @var \Closure(string,?string,int):array{status:int,body:string} */
    private readonly \Closure $http;
    /** @var \Closure():string */
    private readonly \Closure $backup;
    /** @var \Closure():array<string,mixed> */
    private readonly \Closure $migrate;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly string $root,
        private readonly string $storageRoot,
        ?\Closure $http = null,
        ?\Closure $backup = null,
        ?\Closure $migrate = null,
    ) {
        $this->http = $http ?? \Closure::fromCallable([$this, 'curlGet']);
        $this->backup = $backup ?? function (): string {
            $pass = getenv('BACKUP_PASSPHRASE') ?: '';
            $r = (new BackupService($this->db, $this->storageRoot))->create($this->storageRoot . '/backups', $pass === '' ? null : $pass);
            return $r['path'];
        };
        $this->migrate = $migrate ?? function (): array {
            return (new \App\Core\Migrator($this->db, $this->root . '/database/migrations'))->migrate();
        };
    }

    public function currentVersion(): string
    {
        $v = is_file($this->root . '/VERSION') ? trim((string) file_get_contents($this->root . '/VERSION')) : '0.0.0';
        return preg_match('/^\d+\.\d+\.\d+/', $v) === 1 ? $v : '0.0.0';
    }

    /** @return array<string,mixed>|null zuletzt ermittelte Release (aus der Datenbank) */
    public function lastCheck(): ?array
    {
        $raw = $this->db->value('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['update.latest']);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : null;
    }

    /** @return array<string,mixed>|null Ergebnis des letzten Installationsversuchs */
    public function lastRun(): ?array
    {
        $raw = $this->db->value('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['update.last_run']);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * Fragt die neueste Release ab (kein Entwurf, keine Vorabversion – so liefert es die GitHub-API für /releases/latest).
     * @return array{version:string,tag:string,name:string,notes:string,url:string,published_at:string,download_url:string,source:string,checked_at:string,newer:bool}
     */
    public function check(Principal $p): array
    {
        $this->require($p);
        $res = ($this->http)(self::API_LATEST, null, 2_000_000);
        if ($res['status'] === 404) {
            throw new BusinessRuleException('Im Repository ' . self::REPO . ' existiert noch keine veröffentlichte Release.');
        }
        if ($res['status'] !== 200) {
            $this->audit->log('system.update_check_failed', 'failure', null, $p->userId, null, null, ['status' => $res['status']]);
            throw new BusinessRuleException('GitHub antwortet nicht wie erwartet (HTTP ' . $res['status'] . '). Bitte später erneut versuchen.');
        }
        $j = json_decode($res['body'], true);
        if (!is_array($j) || !isset($j['tag_name'])) {
            throw new BusinessRuleException('Unerwartete Antwort von GitHub.');
        }
        $tag = (string) $j['tag_name'];
        $version = ltrim($tag, 'vV');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $version) !== 1) {
            throw new BusinessRuleException('Die Release-Bezeichnung »' . mb_substr($tag, 0, 40) . '« ist keine gültige Versionsnummer (erwartet: 1.2.3 oder v1.2.3).');
        }
        $download = null;
        $source = 'zipball';
        foreach ((array) ($j['assets'] ?? []) as $a) {
            if (is_array($a) && str_ends_with(strtolower((string) ($a['name'] ?? '')), '.zip') && ($a['state'] ?? 'uploaded') === 'uploaded' && $this->hostAllowed((string) ($a['browser_download_url'] ?? ''))) {
                $download = (string) $a['browser_download_url'];
                $source = 'asset';
                break;
            }
        }
        $download ??= (string) ($j['zipball_url'] ?? '');
        if (!$this->hostAllowed($download)) {
            throw new BusinessRuleException('Die Release enthält keine verwendbare Download-Adresse.');
        }
        $out = [
            'version' => $version, 'tag' => $tag, 'name' => mb_substr((string) ($j['name'] ?? $tag), 0, 190),
            'notes' => mb_substr((string) ($j['body'] ?? ''), 0, 4000), 'url' => (string) ($j['html_url'] ?? ''),
            'published_at' => (string) ($j['published_at'] ?? ''), 'download_url' => $download, 'source' => $source,
            'checked_at' => gmdate('c'), 'newer' => version_compare($version, $this->currentVersion(), '>'),
        ];
        $this->setting('update.latest', json_encode($out, JSON_THROW_ON_ERROR));
        $this->audit->log('system.update_checked', 'success', null, $p->userId, null, null, ['latest' => $version, 'current' => $this->currentVersion()]);
        return $out;
    }

    /**
     * Spielt die neueste Release ein. Wirft bei Fehlern; Code wird dann zurückgespielt.
     * @return array{from:string,to:string,backup:string,sha256:string,steps:list<string>,migrations:list<string>}
     */
    public function install(Principal $p, string $expectedVersion): array
    {
        $this->require($p);
        @set_time_limit(600);
        @ignore_user_abort(true);
        if ((int) $this->db->value("SELECT GET_LOCK('vp_update', 0)") !== 1) {
            throw new BusinessRuleException('Es läuft bereits eine Aktualisierung.');
        }
        $steps = [];
        $work = $this->workDir();
        $stamp = gmdate('Ymd-His');
        $rollback = $work . '/rollback-' . $stamp;
        $stage = $work . '/stage-' . $stamp;
        $swapped = [];
        $backupPath = '';
        $maintenance = false;
        try {
            $latest = $this->check($p);
            if ($latest['version'] !== $expectedVersion) {
                throw new BusinessRuleException('Inzwischen ist Version ' . $latest['version'] . ' erschienen (angezeigt wurde ' . $expectedVersion . '). Bitte die Seite neu laden und prüfen.');
            }
            $from = $this->currentVersion();
            if (!$latest['newer']) {
                throw new BusinessRuleException("Version {$from} ist bereits aktuell.");
            }
            $this->preflight($work);
            $steps[] = 'Voraussetzungen geprüft';

            $zip = $work . '/paket-' . $stamp . '.zip';
            $res = ($this->http)($latest['download_url'], $zip, self::MAX_DOWNLOAD);
            if ($res['status'] !== 200 || !is_file($zip) || filesize($zip) < 100) {
                throw new BusinessRuleException('Das Paket konnte nicht geladen werden (HTTP ' . $res['status'] . ').');
            }
            $sha = hash_file('sha256', $zip);
            $steps[] = 'Paket geladen (' . number_format((int) filesize($zip) / 1048576, 1, ',', '’') . ' MB), SHA-256 ' . $sha;
            $this->audit->log('system.update_started', 'success', null, $p->userId, null, null, ['from' => $from, 'to' => $latest['version'], 'sha256' => $sha, 'source' => $latest['source']]);

            $this->extract($zip, $stage);
            $steps[] = 'Paket entpackt und geprüft (Pfade, PHP-Syntax)';
            $staged = trim((string) @file_get_contents($stage . '/VERSION'));
            if ($staged !== $latest['version']) {
                throw new BusinessRuleException("Die Datei VERSION im Paket ({$staged}) passt nicht zur Release {$latest['version']}. Abbruch.");
            }

            $backupPath = ($this->backup)();
            $steps[] = 'Sicherung erstellt: ' . basename($backupPath);

            // Alles, was nach dem Austausch noch gebraucht wird, jetzt laden (der Autoloader zeigt danach auf neuen Code).
            foreach ([\App\Core\Migrator::class, \App\Core\BusinessRuleException::class, \App\Modules\Audit\AuditLogger::class, \App\Web\Session::class] as $c) {
                class_exists($c);
            }

            $this->setMaintenance(true);
            $maintenance = true;
            mkdir($rollback, 0750, true);
            foreach (array_merge(self::DIRS, self::FILES) as $item) {
                $old = $this->root . '/' . $item;
                $new = $stage . '/' . $item;
                if (!file_exists($new) && !file_exists($old)) {
                    continue;
                }
                if (file_exists($old) && !@rename($old, $rollback . '/' . $item)) {
                    throw new BusinessRuleException("»{$item}« kann nicht ersetzt werden (Dateirechte prüfen).");
                }
                $swapped[] = $item;
                if (file_exists($new) && !@rename($new, $old)) {
                    throw new BusinessRuleException("»{$item}« konnte nicht eingespielt werden.");
                }
            }
            $steps[] = 'Programmdateien ausgetauscht';
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $mig = ($this->migrate)();
            $applied = array_values(array_map('strval', (array) ($mig['applied'] ?? [])));
            $steps[] = $applied === [] ? 'Keine neuen Datenbank-Migrationen' : 'Migrationen angewendet: ' . implode(', ', $applied);

            $this->setMaintenance(false);
            $maintenance = false;
            $this->audit->log('system.updated', 'success', null, $p->userId, null, null, ['from' => $from, 'to' => $latest['version'], 'sha256' => $sha, 'migrations' => $applied, 'backup' => basename($backupPath)]);
            $result = ['from' => $from, 'to' => $latest['version'], 'backup' => $backupPath, 'sha256' => $sha, 'steps' => $steps, 'migrations' => $applied, 'at' => gmdate('c'), 'ok' => true];
            $this->setting('update.last_run', json_encode($result, JSON_THROW_ON_ERROR));
            $this->prune($work, 2);
            return $result;
        } catch (\Throwable $e) {
            $note = $this->rollbackCode($swapped, $rollback);
            $msg = $e instanceof BusinessRuleException ? $e->getMessage() : 'Unerwarteter Fehler (' . $e::class . '): ' . mb_substr($e->getMessage(), 0, 200);
            $this->audit->log('system.update_failed', 'failure', null, $p->userId, null, null, ['error' => mb_substr($msg, 0, 300), 'rollback' => $note]);
            $this->setting('update.last_run', json_encode(['ok' => false, 'at' => gmdate('c'), 'steps' => $steps, 'error' => $msg, 'rollback' => $note, 'backup' => $backupPath], JSON_THROW_ON_ERROR));
            throw new BusinessRuleException($msg . ($note !== '' ? ' ' . $note : '') . ($backupPath !== '' ? ' Sicherung vor dem Versuch: ' . basename($backupPath) . '.' : ''));
        } finally {
            if ($maintenance) {
                $this->setMaintenance(false);
            }
            $this->deleteTree($stage);
            $this->db->value("SELECT RELEASE_LOCK('vp_update')");
        }
    }

    // ------------------------------------------------------------------ intern

    private function require(Principal $p): void
    {
        if (!$p->isSystemAdmin || $p->tenantId !== null) {
            throw new ForbiddenException();
        }
    }

    public function hostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true) && !isset($parts['user']) && !isset($parts['port']);
    }

    private function workDir(): string
    {
        $s = realpath($this->storageRoot);
        $r = realpath($this->root);
        $dir = ($s !== false && $r !== false && str_starts_with($s, $r . DIRECTORY_SEPARATOR)) ? $this->storageRoot . '/updates' : $this->root . '/.update-work';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new BusinessRuleException('Arbeitsverzeichnis für Updates kann nicht angelegt werden.');
        }
        return $dir;
    }

    private function preflight(string $work): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new BusinessRuleException('PHP-Erweiterung zip fehlt.');
        }
        if (!is_writable($this->root) || !is_writable($work)) {
            throw new BusinessRuleException('Projektordner bzw. Arbeitsverzeichnis ist für den Webserver nicht beschreibbar. Dateirechte in Plesk prüfen.');
        }
        foreach (self::DIRS as $d) {
            if (is_dir($this->root . '/' . $d) && !is_writable($this->root . '/' . $d)) {
                throw new BusinessRuleException("Verzeichnis »{$d}« ist nicht beschreibbar.");
            }
        }
        $a = @stat($work);
        $b = @stat($this->root);
        if ($a === false || $b === false || $a['dev'] !== $b['dev']) {
            throw new BusinessRuleException('Arbeitsverzeichnis und Projektordner liegen auf verschiedenen Datenträgern; ein sicherer Austausch ist so nicht möglich.');
        }
        $free = @disk_free_space($work);
        if ($free !== false && $free < 300_000_000) {
            throw new BusinessRuleException('Zu wenig freier Speicherplatz (mindestens 300 MB nötig).');
        }
    }

    private function extract(string $zipFile, string $stage): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new BusinessRuleException('Das Paket ist keine gültige ZIP-Datei.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new BusinessRuleException('Das Paket enthält eine unplausible Anzahl Dateien.');
            }
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (preg_match('#^((?:[^/]+/)?)src/autoload\.php$#', $n, $m) === 1) {
                    $prefix = $m[1];
                    break;
                }
            }
            if ($prefix === null) {
                throw new BusinessRuleException('Das Paket enthält keine gültige Programmstruktur (src/autoload.php fehlt).');
            }
            mkdir($stage, 0750, true);
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1) {
                    if ($rel === '') {
                        continue;
                    }
                    throw new BusinessRuleException('Das Paket enthält unzulässige Pfade. Abbruch.');
                }
                $top = explode('/', $rel, 2)[0];
                if (!in_array($top, self::DIRS, true) && !in_array($top, self::FILES, true)) {
                    continue; // z. B. .github, .env, storage – wird nie übernommen
                }
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new BusinessRuleException('Das Paket enthält symbolische Verknüpfungen. Abbruch.');
                }
                $dest = $stage . '/' . $rel;
                if (str_ends_with($name, '/')) {
                    @mkdir($dest, 0750, true);
                    continue;
                }
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNPACKED) {
                    throw new BusinessRuleException('Das entpackte Paket ist unplausibel gross. Abbruch.');
                }
                @mkdir(dirname($dest), 0750, true);
                $in = $zip->getStream($name);
                $out = $in === false ? false : fopen($dest, 'wb');
                if ($in === false || $out === false) {
                    throw new BusinessRuleException('Eine Datei im Paket kann nicht gelesen werden.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
        foreach (['src/autoload.php', 'src/Web/App.php', 'public/index.php', 'VERSION'] as $must) {
            if (!is_file($stage . '/' . $must)) {
                throw new BusinessRuleException("Im Paket fehlt »{$must}«.");
            }
        }
        // Syntaxprüfung aller PHP-Dateien (ohne sie auszuführen)
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                try {
                    token_get_all((string) file_get_contents($f->getPathname()), TOKEN_PARSE);
                } catch (\ParseError $e) {
                    throw new BusinessRuleException('PHP-Syntaxfehler im Paket (' . substr($f->getPathname(), strlen($stage) + 1) . '). Abbruch.');
                }
            }
        }
    }

    /** @param list<string> $swapped */
    private function rollbackCode(array $swapped, string $rollback): string
    {
        if ($swapped === []) {
            return '';
        }
        $failed = [];
        foreach (array_reverse($swapped) as $item) {
            $cur = $this->root . '/' . $item;
            $old = $rollback . '/' . $item;
            $this->deleteTree($cur);
            if (file_exists($old) && !@rename($old, $cur)) {
                $failed[] = $item;
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return $failed === []
            ? 'Der vorherige Programmstand wurde wiederhergestellt. Bereits angewendete Datenbank-Migrationen bleiben bestehen (nur additiv); bei Bedarf Sicherung zurückspielen.'
            : 'WARNUNG: Rückspielen fehlgeschlagen für: ' . implode(', ', $failed) . '. Alter Stand liegt in ' . basename($rollback) . ' (Verzeichnis storage/updates).';
    }

    private function prune(string $work, int $keep): void
    {
        foreach (['rollback-*', 'paket-*'] as $pat) {
            $l = glob($work . '/' . $pat) ?: [];
            sort($l);
            foreach (array_slice($l, 0, max(0, count($l) - $keep)) as $x) {
                is_dir($x) ? $this->deleteTree($x) : @unlink($x);
            }
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }

    public function setMaintenance(bool $on): void
    {
        $f = $this->storageRoot . '/maintenance.flag';
        if ($on) {
            file_put_contents($f, gmdate('c'));
        } else {
            @unlink($f);
        }
    }

    private function setting(string $key, string $value): void
    {
        $this->db->run('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    }

    /**
     * HTTPS-GET nur zu den erlaubten GitHub-Hosts; Weiterleitungen werden einzeln geprüft.
     * @return array{status:int,body:string}
     */
    private function curlGet(string $url, ?string $dest, int $maxBytes): array
    {
        for ($hop = 0; $hop < 5; $hop++) {
            if (!$this->hostAllowed($url)) {
                throw new BusinessRuleException('Zugriff auf diese Adresse ist nicht erlaubt.');
            }
            $ch = curl_init($url);
            $location = null;
            $written = 0;
            $body = '';
            $fh = null;
            curl_setopt_array($ch, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'Vertragsplattform-Updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, application/octet-stream'],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$location): int {
                    if (stripos($h, 'location:') === 0) {
                        $location = trim(substr($h, 9));
                    }
                    return strlen($h);
                },
                CURLOPT_WRITEFUNCTION => function ($c, string $d) use (&$written, &$body, &$fh, $dest, $maxBytes): int {
                    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
                    if ($code !== 200) {
                        return strlen($d);
                    }
                    $written += strlen($d);
                    if ($written > $maxBytes) {
                        return 0;
                    }
                    if ($dest !== null) {
                        $fh ??= fopen($dest, 'wb');
                        fwrite($fh, $d);
                    } else {
                        $body .= $d;
                    }
                    return strlen($d);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($ch);
            curl_close($ch);
            if (is_resource($fh)) {
                fclose($fh);
            }
            if ($ok === false && $written <= $maxBytes) {
                throw new BusinessRuleException('Verbindung zu GitHub fehlgeschlagen (cURL ' . $err . ').');
            }
            if ($ok === false) {
                throw new BusinessRuleException('Die Antwort überschreitet das Grössenlimit.');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null) {
                $url = $location;
                continue;
            }
            return ['status' => $status, 'body' => $body];
        }
        throw new BusinessRuleException('Zu viele Weiterleitungen.');
    }
}
