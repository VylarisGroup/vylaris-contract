<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

/**
 * Systemdiagnose für die Administration. Jede Prüfung liefert ok | warn | fail und einen erklärenden Text –
 * ohne Geheimnisse preiszugeben. "warn" bedeutet: läuft, aber nicht für den Produktivbetrieb geeignet.
 */
final class Diagnostics
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly string $root,
        private readonly string $providerName,
        private readonly string $providerMode,
        private readonly string $storageRoot,
    ) {
    }

    /** @return list<array{label:string,status:string,detail:string}> */
    public function run(Migrator $migrator): array
    {
        $out = [];
        $add = static function (string $label, string $status, string $detail) use (&$out): void {
            $out[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };

        $add('PHP-Version', PHP_VERSION_ID >= 80300 ? 'ok' : 'fail', PHP_VERSION . (PHP_VERSION_ID >= 80300 ? '' : ' – benötigt wird PHP 8.3 oder neuer'));
        $missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'sodium', 'zip', 'curl', 'openssl', 'dom', 'fileinfo', 'zlib'], static fn (string $e): bool => !extension_loaded($e)));
        $add('PHP-Erweiterungen', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'Alle benötigten Erweiterungen sind geladen.' : 'Fehlen: ' . implode(', ', $missing));

        $pending = array_diff($migrator->available(), array_keys($migrator->applied()));
        $verify = $migrator->verify();
        $add('Datenbank-Migrationen', $pending === [] && $verify === [] ? 'ok' : 'fail', $pending !== [] ? 'Ausstehend: ' . implode(', ', $pending) : ($verify !== [] ? implode(' ', $verify) : 'Alle Migrationen angewendet, Prüfsummen stimmen.'));

        $trg = (int) $this->db->value("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_%'");
        $add('Integritäts-Trigger', $trg >= 16 ? 'ok' : 'warn', $trg >= 16 ? "{$trg} Trigger aktiv (Unveränderlichkeit zusätzlich in der Datenbank abgesichert)." : "Nur {$trg} von 16 Triggern aktiv. Das Datenbankkonto benötigt das TRIGGER-Recht; bis dahin gilt die Absicherung nur in der Anwendung.");

        $key = $this->config->has('APP_KEY') ? $this->config->get('APP_KEY') : '';
        $add('Anwendungsschlüssel', strlen((string) base64_decode($key, true)) === 32 ? 'ok' : 'fail', strlen((string) base64_decode($key, true)) === 32 ? 'Vorhanden (Inhalt wird nie angezeigt). Bei Verlust sind MFA-Geheimnisse nicht mehr entschlüsselbar.' : 'APP_KEY fehlt oder ist ungültig.');

        $storage = realpath($this->storageRoot) ?: $this->storageRoot;
        $public = realpath($this->root . '/public') ?: $this->root . '/public';
        $inside = str_starts_with($storage . '/', $public . '/');
        $writable = is_dir($storage) && is_writable($storage);
        $add('Dokumentenspeicher', !$inside && $writable ? 'ok' : 'fail', $inside ? 'Speicher liegt im öffentlichen Webroot!' : ($writable ? 'Ausserhalb des Webroots, schreibbar.' : 'Nicht schreibbar.'));

        $maxMb = $this->config->int('MAX_UPLOAD_MB', 20);
        $ini = min($this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size')));
        $add('Upload-Limits', $ini >= $maxMb * 1_000_000 ? 'ok' : 'warn', 'PHP erlaubt ' . round($ini / 1_000_000) . ' MB, Anwendung ' . $maxMb . ' MB.');

        $smtp = $this->config->get('SMTP_HOST', '') !== '';
        $q = $this->db->one("SELECT SUM(status IN ('pending','failed')) AS waiting, SUM(status = 'dead') AS dead FROM notifications WHERE channel = 'email'") ?? [];
        $add('E-Mail (SMTP)', $smtp ? ((int) ($q['dead'] ?? 0) > 0 ? 'warn' : 'ok') : 'fail', $smtp
            ? 'SMTP konfiguriert. Wartend: ' . (int) ($q['waiting'] ?? 0) . ', endgültig fehlgeschlagen: ' . (int) ($q['dead'] ?? 0) . '. Zustellung wurde nur mit den dokumentierten Tests geprüft, nicht mit Ihrem Server – senden Sie eine Test-Einladung.'
            : 'SMTP nicht konfiguriert: Einladungen, Passwort-Resets und Benachrichtigungen können nicht versendet werden.');

        $sandbox = $this->providerMode === 'sandbox';
        $add('Signaturanbieter', $sandbox ? 'warn' : 'ok', $this->providerName . ' im Modus „' . $this->providerMode . '“. ' . ($sandbox
            ? 'TESTMODUS: Es entstehen keine rechtsgültigen Signaturen.'
            : 'Die Anbindung muss vor produktivem Einsatz mit Ihrem Anbieterkonto getestet werden; Ergebnisse werden nicht unabhängig validiert.'));

        $cron = $this->db->one("SELECT last_finished_at, last_status FROM maintenance_jobs WHERE job_name = 'tick' ORDER BY last_finished_at DESC LIMIT 1");
        $fresh = $cron !== null && $cron['last_finished_at'] !== null && strtotime((string) $cron['last_finished_at'] . ' UTC') > time() - 1800;
        $add('Cronjob', $fresh ? 'ok' : 'fail', $fresh ? 'Letzter Lauf: ' . $cron['last_finished_at'] . ' UTC.' : 'Kein Lauf in den letzten 30 Minuten. Fristen, Erinnerungen und E-Mail-Versand ruhen. Siehe docs/CRON.md.');

        $anchor = $this->db->one('SELECT anchored_at FROM audit_anchors ORDER BY id DESC LIMIT 1');
        $add('Audit-Anker', $anchor !== null ? 'ok' : 'warn', $anchor !== null ? 'Letzter Anker: ' . $anchor['anchored_at'] . ' UTC. Kopieren Sie den Kettenkopf regelmässig an einen Ort ausserhalb dieser Datenbank.' : 'Noch kein Anker gesetzt (wird vom Cronjob angelegt).');

        $unrev = (int) $this->db->value("SELECT COUNT(*) FROM retention_policies WHERE legal_review_status <> 'reviewed'");
        $add('Aufbewahrungsregeln', $unrev === 0 ? 'ok' : 'warn', $unrev === 0 ? 'Alle Regeln als rechtlich geprüft markiert.' : "{$unrev} Regel(n) nicht als rechtlich geprüft markiert; es erfolgt keine automatische Löschung.");

        return $out;
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1_073_741_824,
            'm' => $n * 1_048_576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
