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
 * Aktualisierung aus dem fest hinterlegten GitHub-Repository (VylarisGroup/vylaris-contract).
 *
 * Grundsätze (Neuentwurf nach einem 403 durch falsche Verzeichnisrechte):
 *  - Es werden NIE ganze Verzeichnisse ausgetauscht (insbesondere nicht public/, das der Dokumentenstamm sein kann),
 *    sondern einzelne Dateien atomar ersetzt. Verzeichnisse behalten ihren Inode, Besitzer und ihre Rechte.
 *  - Rechte werden erzwungen (Verzeichnisse 0755, Dateien 0644), unabhängig von umask und Vorgängerzustand.
 *  - Vor dem Ersetzen wird alles Betroffene gesichert (Journal); bei jedem Fehler – auch bei fehlgeschlagener
 *    Selbstprüfung nach dem Einspielen – wird der alte Stand wiederhergestellt.
 *  - `.env`, `storage/`, `SETUP-SCHLUESSEL.txt` und die .htaccess im Projektstamm werden nie angefasst.
 *
 * Quelle: neueste GitHub-Release; gibt es keine (oder ist der Hauptzweig neuer), das Archiv des Zweigs »main«.
 * ACHTUNG (Vorgabe des Betreibers): keine kryptographische Signaturprüfung. Optional kann eine erwartete SHA-256
 * des Pakets angegeben werden. Die berechnete SHA-256 wird immer protokolliert.
 */
final class UpdateService
{
    public const REPO = 'VylarisGroup/vylaris-contract';
    private const API_LATEST = 'https://api.github.com/repos/VylarisGroup/vylaris-contract/releases/latest';
    private const API_MAIN = 'https://api.github.com/repos/VylarisGroup/vylaris-contract/commits/main';
    private const RAW_VERSION = 'https://raw.githubusercontent.com/VylarisGroup/vylaris-contract/main/VERSION';
    private const BRANCH_ZIP = 'https://codeload.github.com/VylarisGroup/vylaris-contract/zip/refs/heads/main';
    private const HOSTS = ['api.github.com', 'github.com', 'codeload.github.com', 'raw.githubusercontent.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    public const DIRS = ['src', 'templates', 'public', 'bin', 'database', 'docs', 'tests', 'design'];
    public const FILES = ['VERSION', 'README.md', 'composer.json', '.env.example'];
    private const MAX_DOWNLOAD = 80_000_000;
    private const MAX_UNPACKED = 250_000_000;
    private const MAX_ENTRIES = 20000;
    private const MAINTENANCE_TTL = 900;

    /** @var \Closure(string,?string,int):array{status:int,body:string} */
    private readonly \Closure $http;
    /** @var \Closure():string */
    private readonly \Closure $backup;
    /** @var \Closure():array<string,mixed> */
    private readonly \Closure $migrate;
    /** @var \Closure(string):array{state:string,detail:string} state: ok|skipped|failed */
    private readonly \Closure $health;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly string $root,
        private readonly string $storageRoot,
        ?\Closure $http = null,
        ?\Closure $backup = null,
        ?\Closure $migrate = null,
        ?\Closure $health = null,
        private readonly string $appUrl = '',
    ) {
        $this->http = $http ?? \Closure::fromCallable([$this, 'curlGet']);
        $this->backup = $backup ?? function (): string {
            $pass = getenv('BACKUP_PASSPHRASE') ?: '';
            return (new BackupService($this->db, $this->storageRoot))->create($this->storageRoot . '/backups', $pass === '' ? null : $pass)['path'];
        };
        $this->migrate = $migrate ?? fn (): array => (new \App\Core\Migrator($this->db, $this->root . '/database/migrations'))->migrate();
        $this->health = $health ?? \Closure::fromCallable([$this, 'selfCheck']);
    }

    // ------------------------------------------------------------------ Abfragen

    public function currentVersion(): string
    {
        $v = is_file($this->root . '/VERSION') ? trim((string) file_get_contents($this->root . '/VERSION')) : '0.0.0';
        return preg_match('/^\d+\.\d+\.\d+/', $v) === 1 ? $v : '0.0.0';
    }

    /** @return array<string,mixed>|null */
    public function lastCheck(): ?array
    {
        return $this->jsonSetting('update.latest');
    }

    /** @return array<string,mixed>|null */
    public function lastRun(): ?array
    {
        return $this->jsonSetting('update.last_run');
    }

    /**
     * Ermittelt die neueste Version: höchste von GitHub-Release und Hauptzweig (bei Gleichstand die Release).
     * @return array{version:string,tag:string,name:string,notes:string,url:string,published_at:string,download_url:string,source:string,commit:string,checked_at:string,newer:bool}
     */
    public function check(Principal $p): array
    {
        $this->require($p);
        $candidates = [];
        $problems = [];

        $res = ($this->http)(self::API_LATEST, null, 2_000_000);
        if ($res['status'] === 200) {
            $j = json_decode($res['body'], true);
            $tag = is_array($j) ? (string) ($j['tag_name'] ?? '') : '';
            $version = ltrim($tag, 'vV');
            if ($this->validVersion($version)) {
                $download = null;
                $source = 'release-zip';
                foreach ((array) ($j['assets'] ?? []) as $a) {
                    if (is_array($a) && str_ends_with(strtolower((string) ($a['name'] ?? '')), '.zip') && ($a['state'] ?? 'uploaded') === 'uploaded' && $this->hostAllowed((string) ($a['browser_download_url'] ?? ''))) {
                        $download = (string) $a['browser_download_url'];
                        $source = 'release-asset';
                        break;
                    }
                }
                $download ??= (string) ($j['zipball_url'] ?? '');
                if ($this->hostAllowed($download)) {
                    $candidates[] = ['version' => $version, 'tag' => $tag, 'name' => mb_substr((string) ($j['name'] ?? $tag), 0, 190), 'notes' => mb_substr((string) ($j['body'] ?? ''), 0, 4000),
                        'url' => (string) ($j['html_url'] ?? ''), 'published_at' => (string) ($j['published_at'] ?? ''), 'download_url' => $download, 'source' => $source, 'commit' => ''];
                } else {
                    $problems[] = 'Release ohne verwendbare Download-Adresse';
                }
            } else {
                $problems[] = 'Release-Bezeichnung »' . mb_substr($tag, 0, 40) . '« ist keine Versionsnummer';
            }
        } elseif ($res['status'] !== 404) {
            $problems[] = 'GitHub-Releases: HTTP ' . $res['status'];
        }

        try {
            $raw = ($this->http)(self::RAW_VERSION, null, 1000);
            $v = trim($raw['body']);
            if ($raw['status'] === 200 && $this->validVersion($v)) {
                $sha = '';
                try {
                    $c = ($this->http)(self::API_MAIN, null, 2_000_000);
                    $cj = $c['status'] === 200 ? json_decode($c['body'], true) : null;
                    $sha = is_array($cj) && preg_match('/^[0-9a-f]{40}$/', (string) ($cj['sha'] ?? '')) === 1 ? (string) $cj['sha'] : '';
                } catch (\Throwable) {
                }
                $candidates[] = ['version' => $v, 'tag' => 'main', 'name' => 'Hauptzweig (main)', 'notes' => '', 'url' => 'https://github.com/' . self::REPO,
                    'published_at' => '', 'download_url' => self::BRANCH_ZIP, 'source' => 'branch', 'commit' => $sha];
            } elseif ($raw['status'] !== 404) {
                $problems[] = 'Hauptzweig: HTTP ' . $raw['status'];
            }
        } catch (BusinessRuleException $e) {
            $problems[] = $e->getMessage();
        }

        if ($candidates === []) {
            $this->audit->log('system.update_check_failed', 'failure', null, $p->userId, null, null, ['problems' => $problems]);
            throw new BusinessRuleException('Keine Version gefunden' . ($problems !== [] ? ' (' . implode('; ', $problems) . ')' : '') . '. Prüfen Sie Erreichbarkeit von GitHub bzw. ob im Repository eine Datei VERSION (z. B. 1.3.2) existiert.');
        }
        usort($candidates, static function (array $a, array $b): int {
            $c = version_compare($b['version'], $a['version']);
            return $c !== 0 ? $c : (($a['source'] === 'branch' ? 1 : 0) <=> ($b['source'] === 'branch' ? 1 : 0));
        });
        $best = $candidates[0] + ['checked_at' => gmdate('c'), 'newer' => false];
        $best['newer'] = version_compare($best['version'], $this->currentVersion(), '>');
        $this->setting('update.latest', json_encode($best, JSON_THROW_ON_ERROR));
        $this->audit->log('system.update_checked', 'success', null, $p->userId, null, null, ['latest' => $best['version'], 'source' => $best['source'], 'current' => $this->currentVersion()]);
        return $best;
    }

    /**
     * Zustand der Voraussetzungen und Rechte (für die Oberfläche).
     * @return list<array{label:string,status:string,detail:string}>
     */
    public function diagnose(): array
    {
        $out = [];
        $add = static function (string $l, string $s, string $d) use (&$out): void {
            $out[] = ['label' => $l, 'status' => $s, 'detail' => $d];
        };
        $add('PHP-Erweiterungen', class_exists(ZipArchive::class) && function_exists('curl_init') ? 'ok' : 'fail', class_exists(ZipArchive::class) && function_exists('curl_init') ? 'zip und curl vorhanden' : 'zip und/oder curl fehlen');
        $bad = [];
        foreach (self::DIRS as $d) {
            if (is_dir($this->root . '/' . $d) && !is_writable($this->root . '/' . $d)) {
                $bad[] = $d;
            }
        }
        $add('Schreibrechte', $bad === [] ? 'ok' : 'fail', $bad === [] ? 'Programmverzeichnisse beschreibbar' : 'nicht beschreibbar: ' . implode(', ', $bad));
        $perm = $this->permissionProblems();
        $add('Dateirechte (Webserver-Lesezugriff)', $perm === [] ? 'ok' : 'fail', $perm === [] ? 'Verzeichnisse und Dateien sind für den Webserver lesbar' : count($perm) . ' Pfade nicht lesbar für den Webserver, z. B. ' . implode(', ', array_slice($perm, 0, 3)) . ' – Schaltfläche »Rechte reparieren«');
        $free = @disk_free_space($this->storageRoot);
        $add('Freier Speicher', $free === false || $free >= 300_000_000 ? 'ok' : 'fail', $free === false ? 'unbekannt' : number_format($free / 1048576, 0, ',', '’') . ' MB');
        $add('Selbstprüfung', $this->appUrl !== '' ? 'ok' : 'warn', $this->appUrl !== '' ? 'Prüfung über ' . $this->appUrl : 'APP_URL fehlt – nach dem Update kann der Webserver nicht angefragt werden');
        return $out;
    }

    // ------------------------------------------------------------------ Installation

    /**
     * Spielt die neueste Version ein. Bei jedem Fehler wird der alte Stand wiederhergestellt.
     * @return array<string,mixed>
     */
    public function install(Principal $p, string $expectedVersion, string $expectedSha256 = ''): array
    {
        $this->require($p);
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 !== '' && preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1) {
            throw new BusinessRuleException('Die Prüfsumme muss aus 64 Hexadezimalzeichen bestehen (SHA-256).');
        }
        @set_time_limit(900);
        @ignore_user_abort(true);
        if ((int) $this->db->value("SELECT GET_LOCK('vp_update', 0)") !== 1) {
            throw new BusinessRuleException('Es läuft bereits eine Aktualisierung.');
        }
        $steps = [];
        $work = $this->workDir();
        $stamp = gmdate('Ymd-His');
        $stage = $work . '/stage-' . $stamp;
        $journalDir = $work . '/rollback-' . $stamp;
        $token = bin2hex(random_bytes(16));
        $applied = false;
        $maintenance = false;
        $backupPath = '';
        $keepRollback = false;
        try {
            $latest = $this->check($p);
            if ($latest['version'] !== $expectedVersion) {
                throw new BusinessRuleException('Inzwischen ist Version ' . $latest['version'] . ' erschienen (angezeigt wurde ' . $expectedVersion . '). Bitte die Seite neu laden und erneut prüfen.');
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
            $sha = (string) hash_file('sha256', $zip);
            if ($expectedSha256 !== '' && !hash_equals($expectedSha256, $sha)) {
                throw new BusinessRuleException('Die SHA-256 des geladenen Pakets (' . $sha . ') stimmt nicht mit der angegebenen Prüfsumme überein. Es wurde nichts verändert.');
            }
            $steps[] = 'Paket geladen (' . number_format((int) filesize($zip) / 1048576, 1, ',', '’') . ' MB), SHA-256 ' . $sha . ($expectedSha256 !== '' ? ' (stimmt mit Ihrer Angabe überein)' : '');
            $this->audit->log('system.update_started', 'success', null, $p->userId, null, null, ['from' => $from, 'to' => $latest['version'], 'sha256' => $sha, 'source' => $latest['source'], 'pinned' => $expectedSha256 !== '']);

            $files = $this->extract($zip, $stage);
            $steps[] = 'Paket entpackt und geprüft (' . count($files) . ' Dateien; Pfade, PHP-Syntax)';
            $staged = trim((string) @file_get_contents($stage . '/VERSION'));
            if ($staged !== $latest['version']) {
                throw new BusinessRuleException("Die Datei VERSION im Paket ({$staged}) passt nicht zur ermittelten Version {$latest['version']}. Abbruch.");
            }

            $plan = $this->plan($files, $stage);
            $steps[] = 'Änderungen: ' . count($plan['write']) . ' Dateien zu schreiben, ' . count($plan['delete']) . ' zu entfernen, ' . ($plan['same']) . ' unverändert';

            $backupPath = ($this->backup)();
            $steps[] = 'Sicherung erstellt: ' . basename($backupPath);

            foreach ([\App\Core\Migrator::class, \App\Core\BusinessRuleException::class, \App\Modules\Audit\AuditLogger::class] as $c) {
                class_exists($c);
            }

            $this->setMaintenance(true, $token);
            $maintenance = true;
            $this->writeJournal($journalDir, $plan, $from, (string) $latest['version']);
            $applied = true;
            $this->apply($plan, $stage, $journalDir);
            $fixed = $this->normalizePermissions();
            $steps[] = 'Dateien einzeln ersetzt, Rechte erzwungen' . ($fixed > 0 ? " ({$fixed} Rechte korrigiert)" : '');
            $this->verifyApplied($files);
            $steps[] = 'Geschriebene Dateien gegengeprüft (SHA-256)';
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $mig = ($this->migrate)();
            $migrations = array_values(array_map('strval', (array) ($mig['applied'] ?? [])));
            $steps[] = $migrations === [] ? 'Keine neuen Datenbank-Migrationen' : 'Migrationen angewendet: ' . implode(', ', $migrations);

            $h = ($this->health)($token);
            if ($h['state'] === 'failed') {
                throw new BusinessRuleException('Selbstprüfung nach dem Einspielen fehlgeschlagen: ' . $h['detail']);
            }
            $steps[] = $h['state'] === 'ok' ? 'Selbstprüfung bestanden: ' . $h['detail'] : 'Selbstprüfung nicht möglich (' . $h['detail'] . ') – bitte die Seite jetzt von Hand öffnen';

            $this->saveManifest($files);
            $this->setMaintenance(false);
            $maintenance = false;
            $result = ['ok' => true, 'from' => $from, 'to' => $latest['version'], 'backup' => $backupPath, 'sha256' => $sha, 'steps' => $steps, 'migrations' => $migrations, 'health' => $h['state'], 'at' => gmdate('c')];
            $this->audit->log('system.updated', 'success', null, $p->userId, null, null, ['from' => $from, 'to' => $latest['version'], 'sha256' => $sha, 'migrations' => $migrations, 'backup' => basename($backupPath), 'health' => $h['state']]);
            $this->setting('update.last_run', json_encode($result, JSON_THROW_ON_ERROR));
            $this->prune($work, 3);
            return $result;
        } catch (\Throwable $e) {
            $note = '';
            if ($applied) {
                $note = $this->rollback($journalDir);
                $keepRollback = !str_starts_with($note, 'Der vorherige');
            }
            $msg = $e instanceof BusinessRuleException ? $e->getMessage() : 'Unerwarteter Fehler (' . $e::class . '): ' . mb_substr($e->getMessage(), 0, 200);
            $this->audit->log('system.update_failed', 'failure', null, $p->userId, null, null, ['error' => mb_substr($msg, 0, 300), 'rollback' => $note]);
            $this->setting('update.last_run', json_encode(['ok' => false, 'at' => gmdate('c'), 'steps' => $steps, 'error' => $msg, 'rollback' => $note, 'backup' => $backupPath], JSON_THROW_ON_ERROR));
            throw new BusinessRuleException($msg . ($note !== '' ? ' ' . $note : ' Es wurde nichts verändert.') . ($backupPath !== '' ? ' Sicherung vor dem Versuch: ' . basename($backupPath) . '.' : ''));
        } finally {
            if ($maintenance) {
                $this->setMaintenance(false);
            }
            $this->deleteTree($stage);
            if (!$keepRollback && !$applied) {
                $this->deleteTree($journalDir);
            }
            $this->db->value("SELECT RELEASE_LOCK('vp_update')");
        }
    }

    /** Repariert Dateirechte (Verzeichnisse 0755, Dateien 0644) in allen verwalteten Verzeichnissen. */
    public function repairPermissions(?Principal $p = null): int
    {
        if ($p !== null) {
            $this->require($p);
        }
        $n = $this->normalizePermissions();
        if ($p !== null) {
            $this->audit->log('system.permissions_repaired', 'success', null, $p->userId, null, null, ['changed' => $n]);
        }
        return $n;
    }

    /** Stellt den Stand vor dem letzten Update wieder her (auch für die Kommandozeile). Gibt eine Meldung zurück. */
    public function rollbackLast(): string
    {
        $dirs = glob($this->workDir() . '/rollback-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $d) {
            if (is_file($d . '/journal.json') && !is_file($d . '/rolled-back')) {
                $msg = $this->rollback($d);
                $this->setMaintenance(false);
                return $msg;
            }
        }
        throw new BusinessRuleException('Kein Rückfallstand vorhanden.');
    }

    public function setMaintenance(bool $on, string $token = ''): void
    {
        $f = $this->storageRoot . '/maintenance.flag';
        if ($on) {
            file_put_contents($f, json_encode(['at' => gmdate('c'), 'token' => $token]));
        } else {
            @unlink($f);
        }
    }

    public function hostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true) && !isset($parts['user']) && !isset($parts['port']);
    }

    // ------------------------------------------------------------------ Plan / Anwenden / Rückfall

    private function require(Principal $p): void
    {
        if (!$p->isSystemAdmin || $p->tenantId !== null) {
            throw new ForbiddenException();
        }
    }

    private function validVersion(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $v) === 1;
    }

    /** @return array<string,mixed>|null */
    private function jsonSetting(string $key): ?array
    {
        $raw = $this->db->value('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : null;
    }

    private function workDir(): string
    {
        $dir = $this->storageRoot . '/updates';
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
        if (!is_writable($work) || !is_writable($this->storageRoot)) {
            throw new BusinessRuleException('Das Verzeichnis storage/ ist für den Webserver nicht beschreibbar.');
        }
        foreach (self::DIRS as $d) {
            if (is_dir($this->root . '/' . $d) && !is_writable($this->root . '/' . $d)) {
                throw new BusinessRuleException("Verzeichnis »{$d}« ist nicht beschreibbar (Dateirechte/Besitzer in Plesk prüfen).");
            }
        }
        $free = @disk_free_space($work);
        if ($free !== false && $free < 300_000_000) {
            throw new BusinessRuleException('Zu wenig freier Speicherplatz (mindestens 300 MB nötig).');
        }
    }

    /**
     * Entpackt nach $stage (nur erlaubte Wurzeln) und liefert Relativpfad => SHA-256.
     * @return array<string,string>
     */
    private function extract(string $zipFile, string $stage): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new BusinessRuleException('Das Paket ist keine gültige ZIP-Datei.');
        }
        $files = [];
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new BusinessRuleException('Das Paket enthält eine unplausible Anzahl Dateien.');
            }
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^((?:[^/]+/)?)src/autoload\.php$#', (string) $zip->getNameIndex($i), $m) === 1) {
                    $prefix = $m[1];
                    break;
                }
            }
            if ($prefix === null) {
                throw new BusinessRuleException('Das Paket enthält keine gültige Programmstruktur (src/autoload.php fehlt).');
            }
            @mkdir($stage, 0755, true);
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '') {
                    continue;
                }
                if (str_contains($rel, "\0") || str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.?(/|$)#', $rel) === 1) {
                    throw new BusinessRuleException('Das Paket enthält unzulässige Pfade. Abbruch.');
                }
                $top = explode('/', $rel, 2)[0];
                if (!in_array($top, self::DIRS, true) && !in_array($top, self::FILES, true)) {
                    continue;
                }
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new BusinessRuleException('Das Paket enthält symbolische Verknüpfungen. Abbruch.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNPACKED) {
                    throw new BusinessRuleException('Das entpackte Paket ist unplausibel gross. Abbruch.');
                }
                $dest = $stage . '/' . $rel;
                @mkdir(dirname($dest), 0755, true);
                $in = $zip->getStream($name);
                $out = $in === false ? false : fopen($dest, 'wb');
                if ($in === false || $out === false) {
                    throw new BusinessRuleException('Eine Datei im Paket kann nicht gelesen werden.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $files[$rel] = (string) hash_file('sha256', $dest);
            }
        } finally {
            $zip->close();
        }
        foreach (['src/autoload.php', 'src/Web/App.php', 'public/index.php', 'public/.htaccess', 'public/assets/app.css', 'VERSION'] as $must) {
            if (!isset($files[$must])) {
                throw new BusinessRuleException("Im Paket fehlt »{$must}«.");
            }
        }
        foreach ($files as $rel => $_) {
            if (str_ends_with($rel, '.php')) {
                try {
                    token_get_all((string) file_get_contents($stage . '/' . $rel), TOKEN_PARSE);
                } catch (\ParseError) {
                    throw new BusinessRuleException("PHP-Syntaxfehler im Paket ({$rel}). Abbruch.");
                }
            }
        }
        return $files;
    }

    /**
     * @param array<string,string> $files
     * @return array{write:list<string>,delete:list<string>,same:int}
     */
    private function plan(array $files, string $stage): array
    {
        $write = [];
        $same = 0;
        foreach ($files as $rel => $hash) {
            $target = $this->root . '/' . $rel;
            if (is_file($target) && !is_link($target) && hash_file('sha256', $target) === $hash) {
                $same++;
                continue;
            }
            if (is_link($target) || (file_exists($target) && !is_file($target))) {
                throw new BusinessRuleException("»{$rel}« ist kein normaler Dateieintrag und wird nicht überschrieben.");
            }
            $write[] = $rel;
        }
        $delete = [];
        $old = $this->loadManifest();
        foreach (array_keys($old) as $rel) {
            if (!isset($files[$rel]) && is_file($this->root . '/' . $rel) && !is_link($this->root . '/' . $rel) && !str_starts_with($rel, 'database/migrations/')) {
                $delete[] = $rel;
            }
        }
        return ['write' => $write, 'delete' => $delete, 'same' => $same];
    }

    /** @param array{write:list<string>,delete:list<string>,same:int} $plan */
    private function writeJournal(string $dir, array $plan, string $from, string $to): void
    {
        if (!@mkdir($dir . '/files', 0750, true) && !is_dir($dir . '/files')) {
            throw new BusinessRuleException('Rückfallverzeichnis kann nicht angelegt werden.');
        }
        $added = [];
        foreach ($plan['write'] as $rel) {
            $src = $this->root . '/' . $rel;
            if (is_file($src)) {
                $this->copyFile($src, $dir . '/files/' . $rel, 0600);
            } else {
                $added[] = $rel;
            }
        }
        foreach ($plan['delete'] as $rel) {
            $this->copyFile($this->root . '/' . $rel, $dir . '/files/' . $rel, 0600);
        }
        file_put_contents($dir . '/journal.json', json_encode(['from' => $from, 'to' => $to, 'at' => gmdate('c'), 'write' => $plan['write'], 'delete' => $plan['delete'], 'added' => $added], JSON_THROW_ON_ERROR));
    }

    /** @param array{write:list<string>,delete:list<string>,same:int} $plan */
    private function apply(array $plan, string $stage, string $journalDir): void
    {
        foreach ($plan['write'] as $rel) {
            $this->placeFile($stage . '/' . $rel, $this->root . '/' . $rel);
        }
        foreach ($plan['delete'] as $rel) {
            @unlink($this->root . '/' . $rel);
        }
    }

    /** Schreibt atomar (temporäre Datei im Zielordner, dann rename) und erzwingt Rechte 0644 / Ordner 0755. */
    private function placeFile(string $src, string $dst): void
    {
        $dir = dirname($dst);
        $this->ensureDir($dir);
        $tmp = $dir . '/.upd-' . bin2hex(random_bytes(4));
        if (!@copy($src, $tmp)) {
            throw new BusinessRuleException('Datei kann nicht geschrieben werden: ' . substr($dst, strlen($this->root) + 1) . ' (Dateirechte prüfen).');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            throw new BusinessRuleException('Datei kann nicht ersetzt werden: ' . substr($dst, strlen($this->root) + 1) . '.');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $this->ensureDir(dirname($dir));
        if (!@mkdir($dir, 0755) && !is_dir($dir)) {
            throw new BusinessRuleException('Verzeichnis kann nicht angelegt werden: ' . substr($dir, strlen($this->root) + 1) . '.');
        }
        @chmod($dir, 0755);
    }

    private function copyFile(string $src, string $dst, int $mode): void
    {
        @mkdir(dirname($dst), 0750, true);
        if (!@copy($src, $dst)) {
            throw new BusinessRuleException('Sicherungskopie kann nicht angelegt werden: ' . basename($src));
        }
        @chmod($dst, $mode);
    }

    /** @param array<string,string> $files */
    private function verifyApplied(array $files): void
    {
        foreach ($files as $rel => $hash) {
            $t = $this->root . '/' . $rel;
            if (!is_file($t) || hash_file('sha256', $t) !== $hash) {
                throw new BusinessRuleException("Nach dem Schreiben stimmt »{$rel}« nicht mit dem Paket überein.");
            }
        }
    }

    private function rollback(string $dir): string
    {
        $j = is_file($dir . '/journal.json') ? json_decode((string) file_get_contents($dir . '/journal.json'), true) : null;
        if (!is_array($j)) {
            return 'WARNUNG: Rückfallprotokoll fehlt – bitte Dateien von Hand wiederherstellen.';
        }
        $failed = [];
        foreach ((array) $j['write'] as $rel) {
            $rel = (string) $rel;
            $t = $this->root . '/' . $rel;
            $b = $dir . '/files/' . $rel;
            if (is_file($b)) {
                try {
                    $this->placeFile($b, $t);
                } catch (\Throwable) {
                    $failed[] = $rel;
                }
            } elseif (in_array($rel, (array) $j['added'], true)) {
                @unlink($t);
            }
        }
        foreach ((array) $j['delete'] as $rel) {
            $rel = (string) $rel;
            if (is_file($dir . '/files/' . $rel)) {
                try {
                    $this->placeFile($dir . '/files/' . $rel, $this->root . '/' . $rel);
                } catch (\Throwable) {
                    $failed[] = $rel;
                }
            }
        }
        $this->normalizePermissions();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if ($failed === []) {
            file_put_contents($dir . '/rolled-back', gmdate('c'));
            return 'Der vorherige Programmstand (Version ' . (string) $j['from'] . ') wurde wiederhergestellt. Bereits angewendete Datenbank-Migrationen bleiben bestehen (nur additiv); bei Bedarf Sicherung zurückspielen.';
        }
        return 'WARNUNG: Rückfall unvollständig für: ' . implode(', ', array_slice($failed, 0, 5)) . '. Auf der Kommandozeile »php bin/repair.php --rollback« ausführen.';
    }

    // ------------------------------------------------------------------ Rechte, Manifest, Aufräumen

    /** @return list<string> */
    private function permissionProblems(): array
    {
        $bad = [];
        foreach (self::DIRS as $d) {
            $base = $this->root . '/' . $d;
            if (!is_dir($base)) {
                continue;
            }
            if ((fileperms($base) & 0005) !== 0005) {
                $bad[] = $d . '/';
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isLink()) {
                    continue;
                }
                $p = $f->getPathname();
                if (($f->isFile() && (fileperms($p) & 0004) !== 0004) || ($f->isDir() && (fileperms($p) & 0005) !== 0005)) {
                    $bad[] = substr($p, strlen($this->root) + 1);
                }
            }
            foreach (new \DirectoryIterator($base) as $e) {
                if ($e->isDir() && !$e->isDot() && (fileperms($e->getPathname()) & 0005) !== 0005) {
                    $bad[] = $d . '/' . $e->getFilename() . '/';
                }
            }
        }
        return array_values(array_unique($bad));
    }

    private function normalizePermissions(): int
    {
        $n = 0;
        foreach (self::DIRS as $d) {
            $base = $this->root . '/' . $d;
            if (!is_dir($base) || is_link($base)) {
                continue;
            }
            if ((fileperms($base) & 0777) !== 0755 && @chmod($base, 0755)) {
                $n++;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                if ($f->isLink()) {
                    continue;
                }
                $want = $f->isDir() ? 0755 : 0644;
                if ((fileperms($f->getPathname()) & 0777) !== $want && @chmod($f->getPathname(), $want)) {
                    $n++;
                }
            }
        }
        foreach (self::FILES as $f) {
            $p = $this->root . '/' . $f;
            if (is_file($p) && !is_link($p) && (fileperms($p) & 0777) !== 0644 && @chmod($p, 0644)) {
                $n++;
            }
        }
        return $n;
    }

    /** @return array<string,string> */
    private function loadManifest(): array
    {
        $f = $this->workDir() . '/installed-manifest.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($d) ? array_map('strval', $d) : [];
    }

    /** @param array<string,string> $files */
    private function saveManifest(array $files): void
    {
        file_put_contents($this->workDir() . '/installed-manifest.json', json_encode($files, JSON_THROW_ON_ERROR));
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

    private function setting(string $key, string $value): void
    {
        $this->db->run('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    }

    // ------------------------------------------------------------------ Netzwerk

    /**
     * Selbstprüfung: Fragt die eigene Adresse an (mit Wartungs-Token). 4xx/5xx = Fehlschlag (→ Rückfall),
     * nicht erreichbar = »nicht prüfbar« (Warnung).
     * @return array{state:string,detail:string}
     */
    private function selfCheck(string $token): array
    {
        $base = rtrim($this->appUrl, '/');
        if ($base === '' || preg_match('#^https?://#', $base) !== 1 || !function_exists('curl_init')) {
            return ['state' => 'skipped', 'detail' => 'APP_URL oder cURL fehlt'];
        }
        $checks = [['/anmelden', 'text/html', '<html'], ['/assets/app.css', 'text/css', '{']];
        $ok = [];
        foreach ($checks as [$path, $type, $needle]) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['X-Update-Check: ' . $token, 'Cache-Control: no-cache'], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'Vertragsplattform-Selbstpruefung']);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($body === false || $errno !== 0) {
                return ['state' => 'skipped', 'detail' => 'Adresse vom Server aus nicht erreichbar (cURL ' . $errno . ')'];
            }
            if ($status >= 300 && $status < 400) {
                return ['state' => 'skipped', 'detail' => "{$path} leitet weiter (HTTP {$status})"];
            }
            if ($status !== 200) {
                return ['state' => 'failed', 'detail' => "{$path} antwortet mit HTTP {$status}"];
            }
            if (stripos($ctype, $type) === false || !str_contains((string) $body, $needle)) {
                return ['state' => 'failed', 'detail' => "{$path} liefert unerwarteten Inhalt ({$ctype})"];
            }
            $ok[] = $path;
        }
        return ['state' => 'ok', 'detail' => implode(' und ', $ok) . ' erreichbar'];
    }

    /**
     * HTTPS-GET nur zu den erlaubten GitHub-Hosts; Weiterleitungen werden einzeln geprüft.
     * @return array{status:int,body:string}
     */
    private function curlGet(string $url, ?string $dest, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            throw new BusinessRuleException('PHP-Erweiterung curl fehlt.');
        }
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
                CURLOPT_USERAGENT => 'Vertragsplattform-Updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, application/octet-stream, text/plain'],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$location): int {
                    if (stripos($h, 'location:') === 0) {
                        $location = trim(substr($h, 9));
                    }
                    return strlen($h);
                },
                CURLOPT_WRITEFUNCTION => function ($c, string $d) use (&$written, &$body, &$fh, $dest, $maxBytes): int {
                    if ((int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) !== 200) {
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
            if ($ok === false) {
                throw new BusinessRuleException($written > $maxBytes ? 'Die Antwort überschreitet das Grössenlimit.' : 'Verbindung zu GitHub fehlgeschlagen (cURL ' . $err . ').');
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
