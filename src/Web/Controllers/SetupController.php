<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Security\AuthService;
use App\Security\PasswordPolicy;
use App\Web\Session;
use App\Web\Ui;
use App\Web\View;

/**
 * Einmaliger Installationsassistent. Schutz:
 *  - nur aktiv, solange storage/install.lock fehlt (danach 404),
 *  - verlangt den Setup-Schlüssel aus der Datei SETUP-SCHLUESSEL.txt im Projektordner (nicht im Webroot),
 *  - verweigert die Installation, wenn die Datenbank bereits Benutzer enthält,
 *  - erzeugt Anwendungsschlüssel und .env (Rechte 0600) und löscht danach den Setup-Schlüssel.
 */
final class SetupController
{
    public function __construct(private readonly Session $session, private readonly View $view, private readonly string $root)
    {
    }

    public function handle(Request $r): Response
    {
        if ($r->path !== '/setup' && $r->path !== '/setup/db-test' && $r->path !== '/') {
            return Response::redirect('/setup');
        }
        $keyFile = $this->root . '/SETUP-SCHLUESSEL.txt';
        if (!is_file($keyFile)) {
            return $this->page(['fatal' => 'Es ist keine Installation möglich: Die Datei SETUP-SCHLUESSEL.txt fehlt im Projektordner. Sie liegt nur im fertigen Installationspaket (ZIP) bei, nicht im GitHub-Quellcode. Legen Sie die Datei selbst an: Erstellen Sie im Projektordner (oberhalb von public/) eine Textdatei SETUP-SCHLUESSEL.txt, die nur eine zufällige Zeichenfolge von mindestens 20 Zeichen enthält (z. B. mit einem Passwort-Generator), und laden Sie diese Seite neu. Diesen Text geben Sie anschliessend im Assistenten als Setup-Schlüssel ein.', 'checks' => []], 403);
        }
        if (strlen(trim((string) file_get_contents($keyFile))) < 20) {
            return $this->page(['fatal' => 'Der Setup-Schlüssel in SETUP-SCHLUESSEL.txt ist zu kurz oder leer. Bitte eine zufällige Zeichenfolge mit mindestens 20 Zeichen eintragen.', 'checks' => []], 403);
        }
        if ($r->path === '/setup/db-test') {
            return $r->method === 'POST' ? $this->dbTest($r, trim((string) file_get_contents($keyFile))) : Response::redirect('/setup');
        }
        if ($r->method === 'POST') {
            $token = (string) ($r->post['_csrf'] ?? '');
            if (!hash_equals($this->session->csrfToken(), $token)) {
                return $this->page(['errors' => ['_' => 'Sitzung abgelaufen. Bitte erneut versuchen.'], 'old' => []], 419);
            }
            return $this->install($r, trim((string) file_get_contents($keyFile)));
        }
        return $this->page(['errors' => [], 'old' => $this->defaults($r)]);
    }

    /** Verbindungstest für Schritt «Datenbank»: nur mit gültiger Sitzung (CSRF) und Setup-Schlüssel, damit kein offener Port-/Zugangs-Scanner entsteht. */
    private function dbTest(Request $r, string $expectedKey): Response
    {
        if (!hash_equals($this->session->csrfToken(), (string) ($r->post['_csrf'] ?? ''))) {
            return Response::json(['ok' => false, 'message' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 419);
        }
        if ($expectedKey === '' || !hash_equals($expectedKey, trim((string) ($r->post['setup_key'] ?? '')))) {
            usleep(1_000_000);
            return Response::json(['ok' => false, 'message' => 'Bitte zuerst im Schritt «Start» den richtigen Setup-Schlüssel eingeben.'], 403);
        }
        $g = static fn (string $k): string => is_string($r->post[$k] ?? null) ? trim((string) $r->post[$k]) : '';
        try {
            $db = Database::connect($g('db_host'), (int) $g('db_port') ?: 3306, $g('db_name'), $g('db_user'), $g('db_pass'));
            $used = (bool) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")
                && (int) $db->value('SELECT COUNT(*) FROM users') > 0;
            if ($used) {
                return Response::json(['ok' => false, 'message' => 'Verbindung klappt, aber die Datenbank enthält schon Benutzer. Bitte eine leere Datenbank verwenden.']);
            }
            return Response::json(['ok' => true, 'message' => 'Verbindung erfolgreich. Die Datenbank ist bereit.']);
        } catch (\Throwable) {
            return Response::json(['ok' => false, 'message' => 'Verbindung fehlgeschlagen. Bitte Host, Port, Datenbankname, Benutzer und Passwort prüfen (Plesk → Datenbanken).']);
        }
    }

    /** @return array<string,string> */
    private function defaults(Request $r): array
    {
        $scheme = $r->isHttps() ? 'https' : 'http';
        return [
            'app_url' => $scheme . '://' . (string) ($r->server['HTTP_HOST'] ?? 'localhost'),
            'db_host' => 'localhost', 'db_port' => '3306', 'smtp_port' => '465', 'smtp_encryption' => 'tls', 'max_upload_mb' => '20',
        ];
    }

    /** @return list<array{label:string,ok:bool,detail:string}> */
    private function checks(): array
    {
        $c = [];
        $c[] = ['label' => 'PHP 8.3 oder neuer', 'ok' => PHP_VERSION_ID >= 80300, 'detail' => PHP_VERSION];
        foreach (['pdo_mysql', 'mbstring', 'sodium', 'zip', 'curl', 'openssl', 'dom', 'fileinfo', 'zlib'] as $e) {
            $c[] = ['label' => 'PHP-Erweiterung ' . $e, 'ok' => extension_loaded($e), 'detail' => extension_loaded($e) ? 'vorhanden' : 'fehlt – im Plesk unter „PHP-Einstellungen“ aktivieren'];
        }
        foreach (['storage', 'storage/private', 'storage/sessions', 'storage/logs', 'storage/backups'] as $d) {
            $path = $this->root . '/' . $d;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $c[] = ['label' => 'Verzeichnis ' . $d . ' beschreibbar', 'ok' => is_dir($path) && is_writable($path), 'detail' => is_writable($path) ? 'ok' : 'Rechte prüfen (Schreibrecht für den Webserver-Benutzer)'];
        }
        $c[] = ['label' => 'Projektordner für .env beschreibbar', 'ok' => is_writable($this->root), 'detail' => is_writable($this->root) ? 'ok' : 'Rechte prüfen'];
        return $c;
    }

    /** @param array<string,mixed> $data */
    private function page(array $data, int $status = 200): Response
    {
        $old = $data['old'] ?? [];
        $ui = new Ui($this->session->csrfToken(), $old, $data['errors'] ?? []);
        $this->view->share('ui', $ui);
        $this->view->share('flash', []);
        $this->view->share('p', null);
        $this->view->share('user', null);
        $this->view->share('csrf', $this->session->csrfToken());
        $this->view->share('unread', 0);
        $this->view->share('path', '/setup');
        $this->view->share('sandbox', false);
        $data += ['checks' => $this->checks(), 'warnings' => [], 'fatal' => null, 'errors' => [], 'old' => []];
        $html = $this->view->render('setup/index', $data, 'layout/guest');
        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    private function install(Request $r, string $expectedKey): Response
    {
        $in = [];
        foreach (['setup_key', 'app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'admin_email', 'admin_name', 'admin_password', 'admin_password2',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_pass', 'mail_from', 'max_upload_mb'] as $k) {
            $v = $r->post[$k] ?? '';
            $in[$k] = is_string($v) ? trim($v) : '';
        }
        $old = $in;
        unset($old['db_pass'], $old['admin_password'], $old['admin_password2'], $old['smtp_pass'], $old['setup_key']);
        $err = [];

        if ($expectedKey === '' || !hash_equals($expectedKey, $in['setup_key'])) {
            usleep(1_000_000);
            $err['setup_key'] = 'Der Setup-Schlüssel ist nicht korrekt.';
        }
        foreach ($in as $k => $v) {
            if (preg_match('/[\r\n"]/', $v) === 1) {
                $err[$k] = 'Zeilenumbrüche und Anführungszeichen sind hier nicht erlaubt.';
            }
        }
        $url = rtrim($in['app_url'], '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true) || $host === '') {
            $err['app_url'] = 'Bitte die vollständige Adresse angeben (https://…).';
        } elseif (parse_url($url, PHP_URL_SCHEME) !== 'https' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            $err['app_url'] = 'Produktivbetrieb erfordert HTTPS. Aktivieren Sie in Plesk ein Zertifikat (z. B. Let’s Encrypt).';
        }
        foreach (['db_host', 'db_name', 'db_user'] as $k) {
            if ($in[$k] === '') {
                $err[$k] = 'Pflichtfeld.';
            }
        }
        if (filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
            $err['admin_email'] = 'Gültige E-Mail-Adresse erforderlich.';
        }
        if ($in['admin_name'] === '') {
            $err['admin_name'] = 'Name erforderlich.';
        }
        if ($in['admin_password'] !== $in['admin_password2']) {
            $err['admin_password2'] = 'Die Passwörter stimmen nicht überein.';
        } else {
            try {
                PasswordPolicy::assertAcceptable($in['admin_password'], $in['admin_email'], $in['admin_name']);
            } catch (\App\Core\ValidationException $e) {
                $err['admin_password'] = implode(' ', $e->errors);
            }
        }
        if ($in['smtp_host'] !== '') {
            if (!in_array($in['smtp_encryption'], ['tls', 'starttls', 'none'], true)) {
                $err['smtp_encryption'] = 'Ungültig.';
            }
            if (filter_var($in['mail_from'], FILTER_VALIDATE_EMAIL) === false) {
                $err['mail_from'] = 'Absenderadresse erforderlich.';
            }
        }
        $maxMb = (int) $in['max_upload_mb'];
        if ($maxMb < 1 || $maxMb > 200) {
            $err['max_upload_mb'] = '1–200 MB.';
        }
        $warnings = [];
        $db = null;
        if ($err === []) {
            try {
                $db = Database::connect($in['db_host'], (int) $in['db_port'] ?: 3306, $in['db_name'], $in['db_user'], $in['db_pass']);
                if ((bool) $db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")
                    && (int) $db->value('SELECT COUNT(*) FROM users') > 0) {
                    $err['db_name'] = 'Diese Datenbank enthält bereits Benutzer. Aus Sicherheitsgründen wird keine Neuinstallation durchgeführt. Verwenden Sie eine leere Datenbank oder stellen Sie die Konfiguration (.env) aus Ihrer Sicherung wieder her.';
                }
            } catch (\Throwable) {
                $err['db_name'] = 'Datenbankverbindung fehlgeschlagen. Bitte Host, Name, Benutzer und Passwort prüfen.';
            }
        }
        foreach ($this->checks() as $c) {
            if (!$c['ok']) {
                $err['_'] = 'Systemvoraussetzungen nicht erfüllt: ' . $c['label'] . ' (' . $c['detail'] . ').';
                break;
            }
        }
        if ($err !== [] || $db === null) {
            return $this->page(['errors' => $err, 'old' => $old], 422);
        }

        try {
            $migrator = new Migrator($db, $this->root . '/database/migrations');
            $res = $migrator->migrate();
            $warnings = $res['warnings'];
            $appKey = Crypto::generateKey();
            $env = $this->envContent($in, $url, $appKey);
            $envPath = $this->root . '/.env';
            if (file_put_contents($envPath, $env, LOCK_EX) === false) {
                throw new \RuntimeException('.env konnte nicht geschrieben werden.');
            }
            @chmod($envPath, 0600);

            $hash = AuthService::hashPassword($in['admin_password']);
            $db->run(
                "INSERT INTO users (email, display_name, password_hash, is_system_admin, user_type, status, email_verified_at, last_seen_version) VALUES (?,?,?,1,'internal','active', UTC_TIMESTAMP(6), ?)",
                [strtolower($in['admin_email']), $in['admin_name'], $hash, is_file($this->root . '/VERSION') ? trim((string) file_get_contents($this->root . '/VERSION')) : null],
            );
            $uid = $db->lastId();
            $audit = new \App\Modules\Audit\AuditLogger($db, hash('sha256', 'audit-ip|' . $appKey));
            $audit->log('system.installed', 'success', null, $uid, 'user', $uid, ['version' => '1.0'], null, $r->ip());
            file_put_contents($this->root . '/storage/install.lock', gmdate('c') . "\n", LOCK_EX);
            @chmod($this->root . '/storage/install.lock', 0640);
            @unlink($this->root . '/SETUP-SCHLUESSEL.txt');
        } catch (\Throwable $e) {
            @file_put_contents($this->root . '/storage/logs/setup.log', gmdate('c') . ' ' . $e::class . ': ' . $e->getMessage() . "\n", FILE_APPEND);
            return $this->page(['errors' => ['_' => 'Die Installation ist fehlgeschlagen (' . $e::class . '). Details stehen in storage/logs/setup.log. Es wurde nichts freigeschaltet; Sie können es nach der Korrektur erneut versuchen.'], 'old' => $old], 500);
        }
        $this->session->destroy();
        return $this->page(['done' => true, 'warnings' => $warnings, 'errors' => [], 'old' => []]);
    }

    /** @param array<string,string> $in */
    private function envContent(array $in, string $url, string $appKey): string
    {
        $lines = [
            '# Von der Installation erzeugt. Diese Datei enthält Geheimnisse: nie weitergeben, nie ins Webroot legen.',
            'APP_ENV=production',
            'APP_NAME="Vertragsplattform"',
            'APP_URL=' . $url,
            'APP_KEY=' . $appKey,
            'DB_HOST=' . $in['db_host'],
            'DB_PORT=' . ((int) $in['db_port'] ?: 3306),
            'DB_NAME=' . $in['db_name'],
            'DB_USER=' . $in['db_user'],
            'DB_PASS="' . $in['db_pass'] . '"',
            'MAX_UPLOAD_MB=' . (int) $in['max_upload_mb'],
            'SIGNATURE_PROVIDER=sandbox',
            'SMTP_HOST=' . $in['smtp_host'],
            'SMTP_PORT=' . ((int) $in['smtp_port'] ?: 465),
            'SMTP_ENCRYPTION=' . ($in['smtp_encryption'] ?: 'tls'),
            'SMTP_USER=' . $in['smtp_user'],
            'SMTP_PASS="' . $in['smtp_pass'] . '"',
            'MAIL_FROM=' . $in['mail_from'],
            'MAIL_FROM_NAME="Vertragsplattform"',
            'TRUSTED_PROXIES=',
            '# Verschlüsselt die Sicherungen (bin/backup.php). Ohne diese Passphrase sind Sicherungen nicht lesbar - .env getrennt sichern!',
            'BACKUP_PASSPHRASE=' . bin2hex(random_bytes(16)),
            'BACKUP_KEEP=14',
        ];
        return implode("\n", $lines) . "\n";
    }
}
