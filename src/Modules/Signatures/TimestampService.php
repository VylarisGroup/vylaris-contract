<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

use App\Modules\Audit\AuditLogger;
use App\Core\Clock;
use App\Core\Database;

/**
 * Hängt an versiegelte Dokumente einen Zeitstempel nach RFC 3161 an (Zeitstempelstelle = TSA_URL, nur https).
 * Fehlschläge blockieren den Abschluss nie; «nachholen» ist jederzeit möglich. Die Zertifikatskette der Stelle wird
 * in der Plattform nicht geprüft (siehe Rfc3161).
 */
final class TimestampService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly HttpTransport $http,
        private readonly string $url,
    ) {
    }

    public function enabled(): bool
    {
        return str_starts_with($this->url, 'https://');
    }

    /** @return bool true wenn ein Zeitstempel gespeichert wurde */
    public function stamp(int $sid): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $s = $this->db->one("SELECT id, request_ref, final_sha256, tsa_token FROM signing_sessions WHERE id = ? AND state = 'signed'", [$sid]);
        if ($s === null || $s['tsa_token'] !== null) {
            return false;
        }
        try {
            $hash = hex2bin((string) $s['final_sha256']);
            $nonce = random_int(1, PHP_INT_MAX >> 1);
            $res = $this->http->request('POST', $this->url, ['Content-Type' => 'application/timestamp-query', 'Accept' => 'application/timestamp-reply'], Rfc3161::request($hash, $nonce));
            if ($res['status'] !== 200) {
                throw new ProviderException('Zeitstempelstelle antwortet mit HTTP ' . $res['status'] . '.', true, $res['status']);
            }
            $r = Rfc3161::parseResponse($res['body'], $hash, $nonce);
            if (!$r['signature_ok']) {
                throw new ProviderException('Die Signatur im Zeitstempel konnte nicht geprüft werden.', false);
            }
            $this->db->run('UPDATE signing_sessions SET tsa_token = ?, tsa_gen_time = ?, tsa_url = ?, tsa_serial = ? WHERE id = ? AND tsa_token IS NULL', [
                $r['token'], $r['gen_time']->format('Y-m-d H:i:s.u'), mb_substr($this->url, 0, 255), mb_substr($r['serial'], 0, 80), $sid,
            ]);
            $this->audit->log('signing.timestamped', 'success', null, null, 'signature_request', (int) $s['request_ref'], ['tsa_time' => $r['gen_time']->format('c')]);
            return true;
        } catch (\Throwable $e) {
            $this->audit->log('signing.timestamped', 'failure', null, null, 'signature_request', (int) $s['request_ref'], ['grund' => mb_substr($e->getMessage(), 0, 200)]);
            return false;
        }
    }

    /** Holt fehlende Zeitstempel nach. @return int Anzahl */
    public function stampMissing(int $limit = 50): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id FROM signing_sessions WHERE state = 'signed' AND tsa_token IS NULL ORDER BY id DESC LIMIT " . (int) $limit) as $r) {
            $n += $this->stamp((int) $r['id']) ? 1 : 0;
        }
        return $n;
    }
}
