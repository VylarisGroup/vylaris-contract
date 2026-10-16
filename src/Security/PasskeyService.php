<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AuthException;
use App\Core\Clock;
use App\Core\Database;
use App\Core\ValidationException;
use App\Modules\Audit\AuditLogger;

/** Passkeys: Registrierung, Anmeldung ohne Passwort, Verwaltung. Die Challenge hält der Aufrufer (Sitzung) einmalig vor. */
final class PasskeyService
{
    public const MAX_PER_USER = 10;
    private const WINDOW = 900;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
        private readonly ?WebAuthn $webauthn,
        private readonly string $appKey,
    ) {
    }

    public function available(): bool
    {
        return $this->webauthn !== null;
    }

    private function wa(): WebAuthn
    {
        if ($this->webauthn === null) {
            throw new ValidationException(['passkey' => 'Passkeys sind nicht verfügbar: Die öffentliche Adresse (APP_URL) muss eine https-Adresse mit Domainnamen sein.']);
        }
        return $this->webauthn;
    }

    /** Stabile, nicht personenbezogene Benutzerkennung für den Authenticator (HMAC, 32 Byte). */
    public function userHandle(int $userId): string
    {
        return hash_hmac('sha256', 'webauthn-user|' . $userId, $this->appKey, true);
    }

    /** @return list<array{id:int,name:string,created_at:string,last_used_at:?string,backup_eligible:int,transports:?string}> */
    public function list(int $userId): array
    {
        return $this->db->all('SELECT id, name, created_at, last_used_at, backup_eligible, transports FROM passkeys WHERE user_id = ? ORDER BY created_at', [$userId]);
    }

    public function count(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$userId]);
    }

    /** @return array{options:array<string,mixed>,challenge:string} */
    public function beginRegistration(int $userId, string $password): array
    {
        $bucket = RateLimiter::bucket('passkey-reg', (string) $userId);
        $this->limiter->assertBelow($bucket, 10, self::WINDOW);
        $u = $this->db->one('SELECT email, display_name, password_hash FROM users WHERE id = ?', [$userId]);
        if ($u === null || !is_string($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
            $this->limiter->hit($bucket);
            throw new ValidationException(['password' => 'Das Passwort ist nicht korrekt.']);
        }
        if ($this->count($userId) >= self::MAX_PER_USER) {
            throw new ValidationException(['passkey' => 'Es sind höchstens ' . self::MAX_PER_USER . ' Passkeys pro Konto möglich.']);
        }
        $ids = array_map(static fn (array $r): string => (string) $r['credential_id'], $this->db->all('SELECT credential_id FROM passkeys WHERE user_id = ?', [$userId]));
        $challenge = WebAuthn::newChallenge();
        return ['options' => $this->wa()->registrationOptions($challenge, $this->userHandle($userId), (string) $u['email'], (string) $u['display_name'], $ids), 'challenge' => $challenge];
    }

    /** @param array<string,mixed> $credential */
    public function finishRegistration(int $userId, array $credential, string $challenge, string $name, string $ip): int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $name = 'Passkey vom ' . $this->clock->now()->format('d.m.Y');
        }
        try {
            $r = $this->wa()->verifyRegistration($credential, $challenge);
        } catch (WebAuthnException $e) {
            $this->audit->log('auth.passkey_registered', 'failure', null, $userId, 'user', $userId, ['reason' => mb_substr($e->getMessage(), 0, 120)], null, $ip);
            throw new ValidationException(['passkey' => 'Der Passkey konnte nicht überprüft werden. Bitte erneut versuchen.']);
        }
        if ($this->count($userId) >= self::MAX_PER_USER) {
            throw new ValidationException(['passkey' => 'Es sind höchstens ' . self::MAX_PER_USER . ' Passkeys pro Konto möglich.']);
        }
        $hash = hash('sha256', $r['credentialId']);
        if ($this->db->value('SELECT 1 FROM passkeys WHERE credential_hash = ?', [$hash]) !== null) {
            throw new ValidationException(['passkey' => 'Dieser Passkey ist bereits registriert.']);
        }
        $this->db->run(
            'INSERT INTO passkeys (user_id, credential_hash, credential_id, public_key_pem, alg, sign_count, transports, name, backup_eligible, backup_state) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$userId, $hash, $r['credentialId'], $r['publicKeyPem'], $r['alg'], $r['signCount'], $r['transports'] !== '' ? $r['transports'] : null, $name, $r['backupEligible'] ? 1 : 0, $r['backupState'] ? 1 : 0],
        );
        $id = $this->db->lastId();
        $this->audit->log('auth.passkey_registered', 'success', null, $userId, 'passkey', $id, ['name' => $name], null, $ip);
        return $id;
    }

    /** @return array{options:array<string,mixed>,challenge:string} */
    public function beginLogin(string $ip): array
    {
        $this->limiter->assertBelow(RateLimiter::bucket('passkey-ip', $ip), 60, self::WINDOW);
        $this->limiter->hit(RateLimiter::bucket('passkey-ip', $ip)); // jede Anfrage zählt, schützt vor Dauerabfragen
        $challenge = WebAuthn::newChallenge();
        return ['options' => $this->wa()->authenticationOptions($challenge), 'challenge' => $challenge];
    }

    /**
     * @param array<string,mixed> $credential
     * @return int Benutzer-ID
     * @throws AuthException
     */
    public function finishLogin(array $credential, string $challenge, string $ip): int
    {
        $bucket = RateLimiter::bucket('passkey-fail', $ip);
        $this->limiter->assertBelow($bucket, 20, self::WINDOW);
        $fail = function (?int $uid, string $reason) use ($bucket, $ip): never {
            $this->limiter->hit($bucket);
            $this->audit->log('auth.passkey_login', 'failure', null, $uid, 'user', $uid, ['reason' => mb_substr($reason, 0, 120)], null, $ip, $uid === null ? 'anonymous' : null);
            throw new AuthException('Anmeldung mit Passkey nicht möglich.');
        };
        try {
            $raw = WebAuthn::b64uDecode((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        } catch (WebAuthnException) {
            $fail(null, 'bad_credential_id');
        }
        $row = $this->db->one('SELECT p.*, u.status, u.email_verified_at, u.locked_until FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.credential_hash = ?', [hash('sha256', $raw)]);
        if ($row === null) {
            $fail(null, 'unknown_credential');
        }
        $uid = (int) $row['user_id'];
        $handle = (string) ($credential['response']['userHandle'] ?? '');
        try {
            if ($handle !== '' && !hash_equals($this->userHandle($uid), WebAuthn::b64uDecode($handle))) {
                $fail($uid, 'user_handle_mismatch');
            }
            $res = $this->wa()->verifyAssertion($credential, $challenge, (string) $row['public_key_pem'], (int) $row['sign_count']);
        } catch (WebAuthnException $e) {
            $fail($uid, $e->getMessage());
        }
        $now = $this->clock->now();
        $locked = $row['locked_until'] !== null && new \DateTimeImmutable((string) $row['locked_until'], new \DateTimeZone('UTC')) > $now;
        if ($row['status'] !== 'active' || $row['email_verified_at'] === null || $locked) {
            $fail($uid, 'not_usable');
        }
        $this->db->run('UPDATE passkeys SET sign_count = ?, backup_state = ?, last_used_at = ? WHERE id = ?', [$res['signCount'], $res['backupState'] ? 1 : 0, $now->format('Y-m-d H:i:s.u'), (int) $row['id']]);
        $this->db->run('UPDATE users SET failed_logins = 0, last_login_at = ? WHERE id = ?', [$now->format('Y-m-d H:i:s.u'), $uid]);
        $this->audit->log('auth.passkey_login', 'success', null, $uid, 'user', $uid, [], null, $ip);
        return $uid;
    }

    public function remove(int $userId, int $passkeyId): void
    {
        $n = $this->db->execute('DELETE FROM passkeys WHERE id = ? AND user_id = ?', [$passkeyId, $userId]);
        if ($n > 0) {
            $this->audit->log('auth.passkey_removed', 'success', null, $userId, 'passkey', $passkeyId);
        }
    }

    /** Für den Administrator-Reset (Gerät verloren/entwendet). */
    public function removeAll(int $userId, int $actorId, ?int $tenantId): int
    {
        $n = $this->db->execute('DELETE FROM passkeys WHERE user_id = ?', [$userId]);
        if ($n > 0) {
            $this->audit->log('auth.passkeys_reset', 'success', $tenantId, $actorId, 'user', $userId, ['count' => $n]);
        }
        return $n;
    }
}
