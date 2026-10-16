<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Core\Database;
use RuntimeException;

/**
 * Append-only Audit-Protokoll mit Hash-Verkettung.
 *
 * entry_hash = SHA-256( prev_hash | kanonische Felder ). Eine nachträgliche Änderung oder
 * Löschung eines Eintrags bricht die Kette ab dem betroffenen Eintrag und wird von verify()
 * erkannt. GRENZEN (siehe docs/SECURITY.md): Wer die gesamte Datenbank kontrolliert, kann die
 * Kette ab einem Punkt neu berechnen. Darum werden Kettenköpfe als Anker (audit_anchors und
 * optional extern per E-Mail/Datei) gesichert; nur ein ausserhalb der DB gesicherter Anker
 * erlaubt die Erkennung einer vollständigen Neuberechnung. Ein Hash beweist weder Urheberschaft
 * noch Rechtsgültigkeit.
 */
final class AuditLogger
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Schlüsselfragmente, die nie in Metadaten gelangen dürfen. */
    private const FORBIDDEN_KEYS = ['password', 'passwort', 'token', 'secret', 'session', 'private', 'authorization', 'cookie', 'body', 'content', 'mfa', 'recovery'];

    public function __construct(
        private readonly Database $db,
        private readonly string $ipHashKey = '',
    ) {
    }

    private ?string $ctxIp = null;
    private ?string $ctxCorrelation = null;

    /** Setzt Standardwerte (Client-IP, Korrelations-ID) für Einträge, die sie nicht selbst mitgeben. */
    public function setContext(?string $ip, ?string $correlationId): void
    {
        $this->ctxIp = $ip;
        $this->ctxCorrelation = $correlationId;
    }

    /**
     * @param array<string,mixed> $metadata datensparsam; sensible Schlüssel werden entfernt
     */
    public function log(
        string $action,
        string $outcome = 'success',
        ?int $tenantId = null,
        ?int $actorUserId = null,
        ?string $objectType = null,
        ?int $objectId = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $ip = null,
        ?string $actorLabel = null,
    ): string {
        if (!in_array($outcome, ['success', 'denied', 'failure'], true)) {
            throw new RuntimeException('Ungültiges Audit-Ergebnis.');
        }
        $ip ??= $this->ctxIp;
        $correlationId ??= $this->ctxCorrelation;
        $eventId = bin2hex(random_bytes(16));
        $occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $meta = $this->sanitize($metadata);
        $metaJson = $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ipHash = $ip !== null ? hash_hmac('sha256', $ip, $this->ipHashKey !== '' ? $this->ipHashKey : 'audit-ip') : null;

        $lock = (int) $this->db->value("SELECT GET_LOCK('audit_chain', 10)");
        if ($lock !== 1) {
            throw new RuntimeException('Audit-Kette konnte nicht gesperrt werden.');
        }
        try {
            $prev = $this->db->value('SELECT entry_hash FROM audit_events ORDER BY seq DESC LIMIT 1');
            $prevHash = is_string($prev) ? $prev : self::GENESIS;
            $entryHash = self::computeHash($prevHash, $eventId, $occurredAt, $tenantId, $actorUserId, $actorLabel, $action, $objectType, $objectId, $outcome, $correlationId, $ipHash, $metaJson);
            $this->db->run(
                'INSERT INTO audit_events (event_id, occurred_at, tenant_id, actor_user_id, actor_label, action, object_type, object_id, outcome, correlation_id, ip_hash, metadata, prev_hash, entry_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$eventId, $occurredAt, $tenantId, $actorUserId, $actorLabel, $action, $objectType, $objectId, $outcome, $correlationId, $ipHash, $metaJson, $prevHash, $entryHash],
            );
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('audit_chain')");
        }
        return $eventId;
    }

    public static function computeHash(
        string $prevHash,
        string $eventId,
        string $occurredAt,
        ?int $tenantId,
        ?int $actorUserId,
        ?string $actorLabel,
        string $action,
        ?string $objectType,
        ?int $objectId,
        string $outcome,
        ?string $correlationId,
        ?string $ipHash,
        ?string $metaJson,
    ): string {
        $canonical = json_encode([
            $prevHash, $eventId, $occurredAt, $tenantId, $actorUserId, $actorLabel, $action,
            $objectType, $objectId, $outcome, $correlationId, $ipHash, $metaJson,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $canonical);
    }

    /**
     * Prüft die Kette. Gibt den ersten fehlerhaften seq-Wert zurück oder null, wenn intakt.
     * Optional wird der Kettenkopf gegen einen extern gesicherten Anker geprüft.
     *
     * @return array{ok:bool,checked:int,first_bad_seq:?int,reason:?string}
     */
    public function verify(?string $expectedHeadHash = null): array
    {
        $prev = self::GENESIS;
        $checked = 0;
        $lastSeq = 0;
        $stmt = $this->db->run('SELECT * FROM audit_events ORDER BY seq ASC');
        while ($row = $stmt->fetch()) {
            $checked++;
            $lastSeq = (int) $row['seq'];
            if (!hash_equals($prev, (string) $row['prev_hash'])) {
                return ['ok' => false, 'checked' => $checked, 'first_bad_seq' => $lastSeq, 'reason' => 'Verkettung unterbrochen (Eintrag fehlt oder wurde eingefügt)'];
            }
            $calc = self::computeHash(
                (string) $row['prev_hash'],
                (string) $row['event_id'],
                self::normalizeTs((string) $row['occurred_at']),
                $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
                $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                $row['actor_label'] === null ? null : (string) $row['actor_label'],
                (string) $row['action'],
                $row['object_type'] === null ? null : (string) $row['object_type'],
                $row['object_id'] === null ? null : (int) $row['object_id'],
                (string) $row['outcome'],
                $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
                $row['ip_hash'] === null ? null : (string) $row['ip_hash'],
                $row['metadata'] === null ? null : self::normalizeJson((string) $row['metadata']),
            );
            if (!hash_equals($calc, (string) $row['entry_hash'])) {
                return ['ok' => false, 'checked' => $checked, 'first_bad_seq' => $lastSeq, 'reason' => 'Inhalt des Eintrags stimmt nicht mit seinem Hash überein'];
            }
            $prev = (string) $row['entry_hash'];
        }
        if ($expectedHeadHash !== null && !hash_equals($expectedHeadHash, $prev)) {
            return ['ok' => false, 'checked' => $checked, 'first_bad_seq' => null, 'reason' => 'Kettenkopf weicht vom gesicherten Anker ab (Kürzung oder Neuberechnung)'];
        }
        // Anker-Prüfung: der letzte gespeicherte Anker muss in der Kette vorkommen.
        $anchor = $this->db->one('SELECT last_seq, head_hash FROM audit_anchors ORDER BY id DESC LIMIT 1');
        if ($anchor !== null) {
            $at = $this->db->one('SELECT entry_hash FROM audit_events WHERE seq = ?', [(int) $anchor['last_seq']]);
            if ($at === null || !hash_equals((string) $anchor['head_hash'], (string) $at['entry_hash'])) {
                return ['ok' => false, 'checked' => $checked, 'first_bad_seq' => (int) $anchor['last_seq'], 'reason' => 'Gespeicherter Anker passt nicht zur Kette'];
            }
        }
        return ['ok' => true, 'checked' => $checked, 'first_bad_seq' => null, 'reason' => null];
    }

    /** Sichert den aktuellen Kettenkopf (intern in audit_anchors; Rückgabe für externe Ablage). */
    public function anchor(?string $note = null): ?array
    {
        $head = $this->db->one('SELECT seq, entry_hash FROM audit_events ORDER BY seq DESC LIMIT 1');
        if ($head === null) {
            return null;
        }
        $this->db->run(
            'INSERT INTO audit_anchors (last_seq, head_hash, external_note) VALUES (?,?,?)',
            [(int) $head['seq'], (string) $head['entry_hash'], $note],
        );
        return ['seq' => (int) $head['seq'], 'head_hash' => (string) $head['entry_hash']];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::FORBIDDEN_KEYS as $bad) {
                if (str_contains($lk, $bad)) {
                    continue 2;
                }
            }
            if (is_array($v)) {
                $v = $this->sanitize($v);
            } elseif (is_string($v)) {
                $v = mb_substr($v, 0, 300);
            } elseif (!is_scalar($v) && $v !== null) {
                continue;
            }
            $out[(string) $k] = $v;
        }
        return $out;
    }

    private static function normalizeTs(string $ts): string
    {
        // DATETIME(6) wird von MariaDB mit 6 Nachkommastellen zurückgegeben.
        return strlen($ts) === 19 ? $ts . '.000000' : $ts;
    }

    private static function normalizeJson(string $json): string
    {
        // MariaDB speichert JSON als LONGTEXT unverändert; unveränderte Rückgabe ist hier korrekt.
        return $json;
    }
}
