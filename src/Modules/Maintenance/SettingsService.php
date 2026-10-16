<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;

/**
 * Betriebseinstellungen, die die Systemadministration in der Oberfläche pflegt. Gespeichert in system_settings
 * (Schlüssel »cfg.NAME«); Geheimnisse mit APP_KEY verschlüsselt. Ein gespeicherter Wert hat Vorrang vor .env;
 * »Zurücksetzen« löscht ihn und der .env-Wert gilt wieder. Nicht hier: DB-Zugang, APP_KEY, STORAGE_PATH und die
 * Sicherungs-Passphrase (sie darf nicht in der Datenbank liegen, die sie selbst schützt).
 */
final class SettingsService
{
    /** @return array<string,array{group:string,label:string,type:string,secret?:bool,options?:array<string,string>,help?:string,default?:string}> */
    public static function schema(): array
    {
        return [
            'APP_NAME' => ['group' => 'general', 'label' => 'Anwendungsname', 'type' => 'text', 'default' => 'Vertragsplattform', 'help' => 'Erscheint in Titel und E-Mails.'],
            'BRAND_COLOR' => ['group' => 'general', 'label' => 'Markenfarbe', 'type' => 'color', 'default' => '#4f46e5', 'help' => 'Färbt Schaltflächen, Navigation und Links; alle Töne werden automatisch abgeleitet. Sehr helle Farben (z. B. Gelb) ergeben zu wenig Kontrast zu weisser Schrift.'],
            'APP_URL' => ['group' => 'general', 'label' => 'Öffentliche Adresse (URL)', 'type' => 'url', 'help' => 'Beginnt mit https://, ohne Pfad am Ende. Wird für Links in E-Mails verwendet.'],
            'MAX_UPLOAD_MB' => ['group' => 'general', 'label' => 'Upload-Limit pro Datei (MB)', 'type' => 'number', 'default' => '20', 'help' => 'Wirksam ist zusätzlich das PHP-Limit des Servers (upload_max_filesize / post_max_size, in Plesk unter »PHP-Einstellungen«).'],
            'TRUSTED_PROXIES' => ['group' => 'general', 'label' => 'Vertrauenswürdige Proxy-IPs', 'type' => 'text', 'help' => 'Nur bei vorgeschaltetem Proxy/Load-Balancer: kommagetrennte IP-Adressen. Falsche Angaben erlauben das Vortäuschen von IP-Adressen.'],
            'SMTP_HOST' => ['group' => 'mail', 'label' => 'SMTP-Server', 'type' => 'text', 'help' => 'Leer = kein E-Mail-Versand.'],
            'SMTP_PORT' => ['group' => 'mail', 'label' => 'SMTP-Port', 'type' => 'number', 'default' => '465'],
            'SMTP_ENCRYPTION' => ['group' => 'mail', 'label' => 'Verschlüsselung', 'type' => 'select', 'default' => 'tls', 'options' => ['tls' => 'TLS (implizit, Port 465)', 'starttls' => 'STARTTLS (Port 587)', 'none' => 'Keine (nur lokale Tests)']],
            'SMTP_USER' => ['group' => 'mail', 'label' => 'SMTP-Benutzer', 'type' => 'text'],
            'SMTP_PASS' => ['group' => 'mail', 'label' => 'SMTP-Passwort', 'type' => 'password', 'secret' => true],
            'MAIL_FROM' => ['group' => 'mail', 'label' => 'Absenderadresse', 'type' => 'email', 'default' => 'noreply@localhost.invalid'],
            'MAIL_FROM_NAME' => ['group' => 'mail', 'label' => 'Absendername', 'type' => 'text', 'default' => 'Vertragsplattform'],
            'SIGNATURE_PROVIDER' => ['group' => 'signature', 'label' => 'Signaturanbieter', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox (KEINE rechtsgültigen Signaturen)', 'skribble' => 'Skribble'], 'help' => 'Skribble benötigt die drei Zugangsangaben unten.'],
            'SKRIBBLE_BASE_URL' => ['group' => 'signature', 'label' => 'Skribble-API-Adresse', 'type' => 'url', 'help' => 'Laut Skribble-Vertrag/Entwicklerkonto.'],
            'SKRIBBLE_USER' => ['group' => 'signature', 'label' => 'Skribble-Benutzer', 'type' => 'text'],
            'SKRIBBLE_KEY' => ['group' => 'signature', 'label' => 'Skribble-API-Schlüssel', 'type' => 'password', 'secret' => true],
            'SKRIBBLE_MODE' => ['group' => 'signature', 'label' => 'Modus', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox/Demo', 'live' => 'Live (rechtsgültig, kostenpflichtig)'], 'help' => 'Live erst nach bestandenem Test gegen die Demo-Umgebung (docs/SIGNATURE_PROVIDER.md).'],
            'BACKUP_KEEP' => ['group' => 'backup', 'label' => 'Anzahl aufbewahrter Sicherungen', 'type' => 'number', 'default' => '14', 'help' => 'Gilt für Sicherungen per Cron (bin/backup.php) und die Schaltfläche »Aufräumen«.'],
        ];
    }

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit, private readonly string $appKey)
    {
    }

    /** Wendet gespeicherte Werte auf die .env-Konfiguration an; ohne Tabelle/Schlüssel unverändert. */
    public static function overlay(Config $config, Database $db): Config
    {
        try {
            $key = $config->get('APP_KEY');
            return $config->withOverrides((new self($db, new AuditLogger($db, 'x'), $key))->overrides());
        } catch (\Throwable) {
            return $config;
        }
    }

    /** @return array<string,string> */
    public function overrides(): array
    {
        $out = [];
        $schema = self::schema();
        foreach ($this->db->all("SELECT setting_key, setting_value, is_secret FROM system_settings WHERE setting_key LIKE 'cfg.%'") as $r) {
            $k = substr((string) $r['setting_key'], 4);
            if (!isset($schema[$k])) {
                continue;
            }
            try {
                $out[$k] = (int) $r['is_secret'] === 1 ? Crypto::decrypt((string) $r['setting_value'], $this->appKey) : (string) $r['setting_value'];
            } catch (\Throwable) {
                // nicht entschlüsselbar (z. B. APP_KEY gewechselt): .env-Wert gilt
            }
        }
        return $out;
    }

    /**
     * Für die Oberfläche: aktueller Wert (Geheimnisse nie), Herkunft und ob ein Geheimnis gesetzt ist.
     * @return array<string,array<string,mixed>>
     */
    public function view(Config $envOnly): array
    {
        $ov = $this->overrides();
        $out = [];
        foreach (self::schema() as $k => $def) {
            $secret = (bool) ($def['secret'] ?? false);
            $inDb = array_key_exists($k, $ov);
            $effective = $inDb ? $ov[$k] : ($envOnly->has($k) ? $envOnly->get($k) : '');
            $out[$k] = $def + [
                'key' => $k,
                'value' => $secret ? '' : $effective,
                'isSet' => $effective !== '',
                'source' => $inDb ? 'Oberfläche' : ($envOnly->has($k) && $envOnly->get($k) !== '' ? '.env' : 'Standard'),
                'inDb' => $inDb,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input  Feldname => Wert (nur Schlüssel des Schemas, nur der Gruppe $group)
     * @param array<string,mixed> $reset  Feldname => '1' → gespeicherten Wert löschen (.env gilt wieder)
     * @return list<string> geänderte Schlüssel
     */
    public function save(Principal $p, string $group, array $input, array $reset, Config $envOnly): array
    {
        if (!$p->isSystemAdmin || $p->tenantId !== null) {
            throw new ForbiddenException();
        }
        $schema = array_filter(self::schema(), static fn (array $d): bool => $d['group'] === $group);
        if ($schema === []) {
            throw new ValidationException(['group' => 'Unbekannte Einstellungsgruppe.']);
        }
        $current = $this->overrides();
        $effective = [];
        foreach (self::schema() as $k => $d) {
            $effective[$k] = array_key_exists($k, $current) ? $current[$k] : ($envOnly->has($k) ? $envOnly->get($k) : ($d['default'] ?? ''));
        }
        $new = [];
        $errors = [];
        $delete = [];
        foreach ($schema as $k => $def) {
            if (($reset[$k] ?? '') === '1') {
                $delete[] = $k;
                $effective[$k] = $envOnly->has($k) ? $envOnly->get($k) : ($def['default'] ?? '');
                continue;
            }
            $raw = $input[$k] ?? null;
            if (!is_string($raw)) {
                continue;
            }
            $v = trim($raw);
            if (($def['secret'] ?? false) && $v === '') {
                continue; // leer = unverändert
            }
            if (strlen($v) > 500 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v) === 1) {
                $errors[$k] = 'Ungültige Zeichen oder zu lang.';
                continue;
            }
            if (($m = $this->validate($k, $def, $v)) !== null) {
                $errors[$k] = $m;
                continue;
            }
            if ($v !== ($effective[$k] ?? '')) {
                $new[$k] = $v;
            }
            $effective[$k] = $v;
        }
        if (($group === 'signature') && ($effective['SIGNATURE_PROVIDER'] ?? '') === 'skribble') {
            foreach (['SKRIBBLE_BASE_URL' => 'API-Adresse', 'SKRIBBLE_USER' => 'Benutzer', 'SKRIBBLE_KEY' => 'API-Schlüssel'] as $k => $label) {
                if (($effective[$k] ?? '') === '' && !isset($errors[$k])) {
                    $errors[$k] = "Für Skribble ist {$label} erforderlich.";
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $changed = [];
        $this->db->transaction(function () use ($new, $delete, $schema, $p, &$changed): void {
            foreach ($new as $k => $v) {
                $secret = (bool) ($schema[$k]['secret'] ?? false);
                $this->db->run(
                    'INSERT INTO system_settings (setting_key, setting_value, is_secret, updated_by) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)',
                    ['cfg.' . $k, $secret ? Crypto::encrypt($v, $this->appKey) : $v, $secret ? 1 : 0, $p->userId],
                );
                $changed[] = $k;
            }
            foreach ($delete as $k) {
                if ($this->db->execute('DELETE FROM system_settings WHERE setting_key = ?', ['cfg.' . $k]) > 0) {
                    $changed[] = $k;
                }
            }
        });
        $this->audit->log('system.settings_changed', 'success', null, $p->userId, 'settings', null, ['group' => $group, 'keys' => $changed]);
        return $changed;
    }

    /** @param array<string,mixed> $def */
    private function validate(string $k, array $def, string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        switch ($k) {
            case 'APP_URL':
            case 'SKRIBBLE_BASE_URL':
                $u = parse_url($v);
                if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user'])) {
                    return 'Bitte eine https://-Adresse angeben.';
                }
                return null;
            case 'BRAND_COLOR':
                return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? null : 'Farbe im Format #rrggbb.';
            case 'MAX_UPLOAD_MB':
                return ctype_digit($v) && (int) $v >= 1 && (int) $v <= 500 ? null : 'Zahl zwischen 1 und 500.';
            case 'BACKUP_KEEP':
                return ctype_digit($v) && (int) $v >= 1 && (int) $v <= 365 ? null : 'Zahl zwischen 1 und 365.';
            case 'SMTP_PORT':
                return ctype_digit($v) && (int) $v >= 1 && (int) $v <= 65535 ? null : 'Port zwischen 1 und 65535.';
            case 'MAIL_FROM':
                return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? null : 'Ungültige E-Mail-Adresse.';
            case 'TRUSTED_PROXIES':
                foreach (explode(',', $v) as $ip) {
                    if (filter_var(trim($ip), FILTER_VALIDATE_IP) === false) {
                        return 'Nur kommagetrennte IP-Adressen erlaubt.';
                    }
                }
                return null;
            case 'SMTP_HOST':
                return preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $v) === 1 ? null : 'Ungültiger Servername.';
        }
        if (($def['type'] ?? '') === 'select' && !isset($def['options'][$v])) {
            return 'Ungültige Auswahl.';
        }
        return null;
    }
}
