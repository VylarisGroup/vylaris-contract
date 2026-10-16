<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AuthException;
use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\ValidationException;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Modules\Audit\AuditLogger;

/**
 * Authentifizierung: Passwort (Argon2id/bcrypt), Sperre, Rate-Limits, MFA (TOTP + Wiederherstellungscodes),
 * Passwort-Reset, E-Mail-Verifikation, Einladungen. Alle Token: 256 Bit Zufall, nur Hash gespeichert,
 * einmalig verwendbar, befristet. Fehlermeldungen sind generisch (keine Konto-Enumeration).
 */
final class AuthService
{
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 15;
    private const IP_MAX = 40;
    private const ACCOUNT_MAX = 12;
    private const WINDOW = 900;
    private const RESET_TTL_MIN = 60;
    private const VERIFY_TTL_H = 48;
    private const INVITE_TTL_H = 72;

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly string $appKey,
        private readonly string $baseUrl,
        private readonly string $appName = 'Vertragsplattform',
    ) {
    }

    // ------------------------------------------------------------------ Passwort

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * @return array{user_id:int,next:string} next: ok | mfa | mfa_enroll
     * @throws AuthException|\App\Core\TooManyRequestsException
     */
    public function login(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $ipBucket = RateLimiter::bucket('login-ip', $ip);
        $accBucket = RateLimiter::bucket('login-acc', $email);
        $this->limiter->assertBelow($ipBucket, self::IP_MAX, self::WINDOW);
        $this->limiter->assertBelow($accBucket, self::ACCOUNT_MAX, self::WINDOW);

        $now = $this->clock->now();
        $u = $email !== '' && mb_strlen($email) <= 190 ? $this->db->one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        // Immer genau eine Passwortprüfung (Zeitverhalten bei unbekanntem Konto angleichen).
        $hash = $u !== null && is_string($u['password_hash'] ?? null) && $u['password_hash'] !== '' ? $u['password_hash'] : self::dummyHash();
        $passOk = password_verify($password, $hash);

        $locked = $u !== null && $u['locked_until'] !== null && new \DateTimeImmutable((string) $u['locked_until'], new \DateTimeZone('UTC')) > $now;
        $usable = $u !== null && $u['status'] === 'active' && $u['email_verified_at'] !== null && !$locked;

        if (!$passOk || !$usable) {
            $this->limiter->hit($ipBucket);
            $this->limiter->hit($accBucket);
            if ($u !== null && $passOk === false && !$locked) {
                $this->registerFailure($u, $now);
            }
            $this->audit->log('auth.login', 'failure', null, $u !== null ? (int) $u['id'] : null, 'user', $u !== null ? (int) $u['id'] : null, [
                'reason' => $u === null ? 'unknown_user' : ($locked ? 'locked' : (!$passOk ? 'bad_password' : 'not_usable')),
            ], null, $ip, $u === null ? 'anonymous' : null);
            throw new AuthException();
        }
        $uid = (int) $u['id'];
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $uid]);
        }
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$uid]);
        $this->limiter->clear($accBucket);

        $next = 'ok';
        if ($u['mfa_enabled_at'] !== null) {
            $next = 'mfa';
        } elseif ($this->mfaMandatory($u)) {
            $next = 'mfa_enroll';
        }
        if ($next === 'ok') {
            $this->markLogin($uid, $ip);
        }
        return ['user_id' => $uid, 'next' => $next];
    }

    public function markLogin(int $uid, string $ip): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->clock->now()->format('Y-m-d H:i:s.u'), $uid]);
        $this->audit->log('auth.login', 'success', null, $uid, 'user', $uid, [], null, $ip);
    }

    /**
     * Seit Version 1.5.0 ist keine Zwei-Faktor-Anmeldung mehr verpflichtend (TOTP und Passkeys sind freiwillig).
     * Die Methode bleibt bestehen, damit der Einrichtungszwang bei Bedarf wieder aktiviert werden kann.
     *
     * @param array<string,mixed> $user
     */
    public function mfaMandatory(array $user): bool
    {
        return false;
    }

    /** @param array<string,mixed> $u */
    private function registerFailure(array $u, \DateTimeImmutable $now): void
    {
        $fails = (int) $u['failed_logins'] + 1;
        $lock = null;
        if ($fails >= self::MAX_FAILS) {
            $lock = $now->modify('+' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s.u');
        }
        $this->db->run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock !== null ? 0 : $fails, $lock, (int) $u['id']]);
        if ($lock !== null) {
            $this->audit->log('auth.account_locked', 'failure', null, (int) $u['id'], 'user', (int) $u['id'], ['minutes' => self::LOCK_MINUTES]);
        }
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= self::hashPassword(bin2hex(random_bytes(16)));
    }

    public function changePassword(int $userId, string $current, string $new): void
    {
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($u === null || !is_string($u['password_hash']) || !password_verify($current, $u['password_hash'])) {
            throw new ValidationException(['current_password' => 'Das aktuelle Passwort ist nicht korrekt.']);
        }
        PasswordPolicy::assertAcceptable($new, (string) $u['email'], (string) $u['display_name']);
        $this->db->run('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE id = ?', [self::hashPassword($new), $userId]);
        $this->audit->log('auth.password_changed', 'success', null, $userId, 'user', $userId);
    }

    /** Macht alle Sitzungen des Benutzers ungültig. */
    public function logoutEverywhere(int $userId): void
    {
        $this->db->run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
        $this->audit->log('auth.logout_all', 'success', null, $userId, 'user', $userId);
    }

    public function sessionEpoch(int $userId): ?int
    {
        $v = $this->db->value("SELECT session_epoch FROM users WHERE id = ? AND status = 'active'", [$userId]);
        return $v === null || $v === false ? null : (int) $v;
    }

    // ------------------------------------------------------------------ MFA

    /** @return array{secret:string,uri:string} */
    public function beginMfaEnrollment(int $userId): array
    {
        $u = $this->db->one('SELECT email, mfa_enabled_at FROM users WHERE id = ?', [$userId]);
        if ($u === null || $u['mfa_enabled_at'] !== null) {
            throw new ValidationException(['mfa' => 'MFA ist bereits aktiv.']);
        }
        $secret = Totp::generateSecret();
        $this->db->run('UPDATE users SET mfa_secret_enc = ?, mfa_last_counter = NULL WHERE id = ?', [Crypto::encrypt($secret, $this->appKey), $userId]);
        return ['secret' => $secret, 'uri' => Totp::provisioningUri($this->appName, (string) $u['email'], $secret)];
    }

    /** @return list<string> Wiederherstellungscodes im Klartext (werden nur einmal angezeigt) */
    public function confirmMfaEnrollment(int $userId, string $code): array
    {
        $u = $this->db->one('SELECT mfa_secret_enc, mfa_enabled_at FROM users WHERE id = ?', [$userId]);
        if ($u === null || $u['mfa_enabled_at'] !== null || !is_string($u['mfa_secret_enc'])) {
            throw new ValidationException(['code' => 'Es läuft keine MFA-Einrichtung.']);
        }
        $this->limiter->assertBelow(RateLimiter::bucket('mfa', (string) $userId), 8, self::WINDOW);
        $secret = Crypto::decrypt($u['mfa_secret_enc'], $this->appKey);
        $counter = Totp::verify($secret, trim($code), $this->clock->now()->getTimestamp());
        if ($counter === null) {
            $this->limiter->hit(RateLimiter::bucket('mfa', (string) $userId));
            throw new ValidationException(['code' => 'Der Code ist nicht korrekt.']);
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = $this->recoveryHash($c);
        }
        $this->db->run('UPDATE users SET mfa_enabled_at = ?, mfa_last_counter = ?, mfa_recovery_hashes = ?, session_epoch = session_epoch WHERE id = ?', [
            $this->clock->now()->format('Y-m-d H:i:s.u'), $counter, json_encode($hashes, JSON_THROW_ON_ERROR), $userId,
        ]);
        $this->audit->log('auth.mfa_enabled', 'success', null, $userId, 'user', $userId);
        return $codes;
    }

    /** @throws AuthException */
    public function verifyMfa(int $userId, string $code, string $ip = ''): void
    {
        $bucket = RateLimiter::bucket('mfa', (string) $userId);
        $this->limiter->assertBelow($bucket, 8, self::WINDOW);
        $u = $this->db->one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$userId]);
        if ($u === null || $u['mfa_enabled_at'] === null || !is_string($u['mfa_secret_enc'])) {
            throw new AuthException();
        }
        $code = trim($code);
        $ok = false;
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = Crypto::decrypt($u['mfa_secret_enc'], $this->appKey);
            $counter = Totp::verify($secret, $code, $this->clock->now()->getTimestamp());
            if ($counter !== null) {
                // Replay-Schutz: Zähler muss strikt wachsen (atomar).
                $ok = $this->db->execute('UPDATE users SET mfa_last_counter = ? WHERE id = ? AND (mfa_last_counter IS NULL OR mfa_last_counter < ?)', [$counter, $userId, $counter]) === 1;
            }
        } else {
            $ok = $this->consumeRecoveryCode($u, $code);
            if ($ok) {
                $this->audit->log('auth.mfa_recovery_used', 'success', null, $userId, 'user', $userId, [], null, $ip);
            }
        }
        if (!$ok) {
            $this->limiter->hit($bucket);
            $this->audit->log('auth.mfa', 'failure', null, $userId, 'user', $userId, [], null, $ip);
            throw new AuthException('Der Code ist nicht korrekt oder wurde bereits verwendet.');
        }
        $this->limiter->clear($bucket);
        $this->markLogin($userId, $ip);
    }

    /** @param array<string,mixed> $u */
    private function consumeRecoveryCode(array $u, string $input): bool
    {
        $norm = strtoupper(str_replace(['-', ' '], '', $input));
        if (preg_match('/^[0-9A-F]{10}$/', $norm) !== 1 || !is_string($u['mfa_recovery_hashes'])) {
            return false;
        }
        $hashes = json_decode($u['mfa_recovery_hashes'], true);
        if (!is_array($hashes)) {
            return false;
        }
        $h = $this->recoveryHash($norm);
        $found = null;
        foreach ($hashes as $i => $x) {
            if (is_string($x) && hash_equals($x, $h)) {
                $found = $i;
            }
        }
        if ($found === null) {
            return false;
        }
        unset($hashes[$found]);
        // Optimistische Sperre: nur wenn sich die Liste zwischenzeitlich nicht geändert hat.
        return $this->db->execute('UPDATE users SET mfa_recovery_hashes = ? WHERE id = ? AND mfa_recovery_hashes = ?', [
            json_encode(array_values($hashes), JSON_THROW_ON_ERROR), (int) $u['id'], $u['mfa_recovery_hashes'],
        ]) === 1;
    }

    private function recoveryHash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->appKey);
    }

    public function disableMfa(int $userId, string $password): void
    {
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($u === null || !is_string($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
            throw new ValidationException(['password' => 'Das Passwort ist nicht korrekt.']);
        }
        if ($this->mfaMandatory($u)) {
            throw new ValidationException(['mfa' => 'Für Ihre Rolle ist MFA verpflichtend und kann nicht deaktiviert werden.']);
        }
        $this->db->run('UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_recovery_hashes = NULL, mfa_last_counter = NULL, session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
        $this->audit->log('auth.mfa_disabled', 'success', null, $userId, 'user', $userId);
    }

    /** Administrativer Reset (z. B. Gerät verloren): nur über Aufrufer mit user.manage. */
    public function adminResetMfa(int $targetUserId, int $actorId, ?int $tenantId): void
    {
        $this->db->run('UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_recovery_hashes = NULL, mfa_last_counter = NULL, session_epoch = session_epoch + 1 WHERE id = ?', [$targetUserId]);
        $this->audit->log('auth.mfa_reset_by_admin', 'success', $tenantId, $actorId, 'user', $targetUserId);
        $n = $this->db->execute('DELETE FROM passkeys WHERE user_id = ?', [$targetUserId]);
        if ($n > 0) {
            $this->audit->log('auth.passkeys_reset', 'success', $tenantId, $actorId, 'user', $targetUserId, ['count' => $n]);
        }
    }

    // ------------------------------------------------------------------ Token (Reset / Verifikation / Einladung)

    /** Antwortet immer gleich – ob das Konto existiert, ist nicht erkennbar. */
    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        $ipB = RateLimiter::bucket('reset-ip', $ip);
        $emB = RateLimiter::bucket('reset-mail', $email);
        $this->limiter->assertBelow($ipB, 15, 3600);
        $this->limiter->hit($ipB);
        if ($this->limiter->count($emB, 3600) >= 3) {
            return; // stilles Limit pro Adresse (kein Mail-Bombing), gleiche Antwort
        }
        $this->limiter->hit($emB);
        $u = $this->db->one("SELECT id, email, display_name FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($u === null) {
            return;
        }
        $token = $this->issueToken((int) $u['id'], 'password_reset', self::RESET_TTL_MIN * 60);
        $this->safeMail((string) $u['email'], (string) $u['display_name'], $this->appName . ': Passwort zurücksetzen',
            "Guten Tag\n\nFür Ihr Konto wurde das Zurücksetzen des Passworts angefordert. Der Link ist " . self::RESET_TTL_MIN . " Minuten gültig und nur einmal verwendbar:\n\n"
            . rtrim($this->baseUrl, '/') . '/passwort/zuruecksetzen/' . $token . "\n\nWenn Sie das nicht waren, ignorieren Sie diese Nachricht.");
        $this->audit->log('auth.reset_requested', 'success', null, (int) $u['id'], 'user', (int) $u['id'], [], null, $ip);
    }

    public function resetTokenValid(string $token): bool
    {
        return $this->findToken($token, 'password_reset') !== null;
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $t = $this->findToken($token, 'password_reset');
        if ($t === null) {
            throw new ValidationException(['token' => 'Der Link ist ungültig oder abgelaufen.']);
        }
        $u = $this->db->one('SELECT email, display_name FROM users WHERE id = ?', [(int) $t['user_id']]);
        PasswordPolicy::assertAcceptable($newPassword, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? ''));
        $this->db->transaction(function () use ($t, $newPassword): void {
            if (!$this->consumeToken((int) $t['id'])) {
                throw new ValidationException(['token' => 'Der Link ist ungültig oder abgelaufen.']);
            }
            $this->db->run('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL, session_epoch = session_epoch + 1 WHERE id = ?', [self::hashPassword($newPassword), (int) $t['user_id']]);
            $this->db->run("UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND purpose = 'password_reset' AND used_at IS NULL", [$this->clock->now()->format('Y-m-d H:i:s.u'), (int) $t['user_id']]);
        });
        $this->audit->log('auth.password_reset', 'success', null, (int) $t['user_id'], 'user', (int) $t['user_id']);
    }

    public function sendEmailVerification(int $userId): void
    {
        $u = $this->db->one('SELECT email, display_name FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            return;
        }
        $token = $this->issueToken($userId, 'email_verify', self::VERIFY_TTL_H * 3600);
        $this->safeMail((string) $u['email'], (string) $u['display_name'], $this->appName . ': E-Mail-Adresse bestätigen',
            "Guten Tag\n\nBitte bestätigen Sie Ihre E-Mail-Adresse:\n\n" . rtrim($this->baseUrl, '/') . '/email/bestaetigen/' . $token . "\n\nDer Link ist " . self::VERIFY_TTL_H . ' Stunden gültig.');
    }

    public function verifyEmail(string $token): int
    {
        $t = $this->findToken($token, 'email_verify');
        if ($t === null || !$this->consumeToken((int) $t['id'])) {
            throw new ValidationException(['token' => 'Der Link ist ungültig oder abgelaufen.']);
        }
        $this->db->run("UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?", [$this->clock->now()->format('Y-m-d H:i:s.u'), (int) $t['user_id']]);
        $this->audit->log('auth.email_verified', 'success', null, (int) $t['user_id'], 'user', (int) $t['user_id']);
        return (int) $t['user_id'];
    }

    /** Einladung: erzeugt Token (Klartext nur für die Mail) – Konto bleibt "pending" bis zur Annahme. */
    public function sendInvitation(int $userId, string $inviterName, string $context): void
    {
        $u = $this->db->one('SELECT email, display_name FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            return;
        }
        $token = $this->issueToken($userId, 'invite', self::INVITE_TTL_H * 3600);
        $this->safeMail((string) $u['email'], (string) $u['display_name'], $this->appName . ': Einladung',
            "Guten Tag {$u['display_name']}\n\n{$inviterName} hat Sie eingeladen ({$context}). Legen Sie hier Ihr Passwort fest:\n\n"
            . rtrim($this->baseUrl, '/') . '/einladung/' . $token . "\n\nDer Link ist " . self::INVITE_TTL_H . ' Stunden gültig und nur einmal verwendbar.');
    }

    public function inviteValid(string $token): bool
    {
        return $this->findToken($token, 'invite') !== null;
    }

    public function acceptInvitation(string $token, string $password): int
    {
        $t = $this->findToken($token, 'invite');
        if ($t === null) {
            throw new ValidationException(['token' => 'Die Einladung ist ungültig oder abgelaufen.']);
        }
        $u = $this->db->one('SELECT email, display_name FROM users WHERE id = ?', [(int) $t['user_id']]);
        PasswordPolicy::assertAcceptable($password, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? ''));
        $this->db->transaction(function () use ($t, $password): void {
            if (!$this->consumeToken((int) $t['id'])) {
                throw new ValidationException(['token' => 'Die Einladung ist ungültig oder abgelaufen.']);
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            // Die Einladung erreichte die Mailbox => E-Mail gilt als bestätigt.
            $this->db->run("UPDATE users SET password_hash = ?, status = 'active', email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ? AND status IN ('pending','active')", [self::hashPassword($password), $now, (int) $t['user_id']]);
        });
        $this->audit->log('auth.invitation_accepted', 'success', null, (int) $t['user_id'], 'user', (int) $t['user_id']);
        return (int) $t['user_id'];
    }

    // ------------------------------------------------------------------ intern

    private function issueToken(int $userId, string $purpose, int $ttlSeconds): string
    {
        $this->db->run('UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$this->clock->now()->format('Y-m-d H:i:s.u'), $userId, $purpose]);
        $token = Crypto::token(32);
        $this->db->run('INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, created_at) VALUES (?,?,?,?,?)', [
            $userId, $purpose, Crypto::tokenHash($token),
            $this->clock->now()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u'), $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
        return $token;
    }

    /** @return array<string,mixed>|null */
    private function findToken(string $token, string $purpose): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token) !== 1) {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::tokenHash($token), $purpose, $this->clock->now()->format('Y-m-d H:i:s.u')],
        );
    }

    private function consumeToken(int $id): bool
    {
        return $this->db->execute('UPDATE auth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?', [
            $this->clock->now()->format('Y-m-d H:i:s.u'), $id, $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]) === 1;
    }

    private function safeMail(string $to, string $name, string $subject, string $body): void
    {
        try {
            $this->mailer->send($to, $name, $subject, $body);
        } catch (MailException $e) {
            $this->audit->log('mail.send_failed', 'failure', null, null, null, null, ['subject_class' => 'auth']);
        }
    }
}
