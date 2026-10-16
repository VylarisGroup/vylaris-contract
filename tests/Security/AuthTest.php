<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AuthException;
use App\Core\TooManyRequestsException;
use App\Core\ValidationException;
use App\Security\AuthService;
use App\Security\PasswordPolicy;
use App\Security\Totp;
use Tests\Support\TestApp;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    private TestApp $app;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    public function testTotpMatchesRfc6238Vector(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, 1));      // T=59 s
        $this->assertSame('081804', Totp::code($secret, 37037036)); // T=1111111109 s
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        $this->assertSame(null, Totp::verify($secret, 'abcdef', 59));
    }

    public function testMfaIsMandatoryForAuditorsAndNotaries(): void
    {
        $base = ['id' => 0, 'is_system_admin' => 0];
        $this->assertTrue($this->app->auth->mfaMandatory($base + ['user_type' => 'auditor']));
        $this->assertTrue($this->app->auth->mfaMandatory($base + ['user_type' => 'notary']));
        $this->assertFalse($this->app->auth->mfaMandatory($base + ['user_type' => 'external_party']));
    }

    public function testLoginSucceedsWithCorrectPassword(): void
    {
        $u = $this->app->loginUser();
        $r = $this->app->auth->login($u['email'], 'Korrektes-Pferd-42', '203.0.113.5');
        $this->assertSame($u['id'], $r['user_id']);
        $this->assertSame('ok', $r['next']);
    }

    public function testWrongPasswordUnknownUserAndUnverifiedGiveSameError(): void
    {
        $u = $this->app->loginUser();
        $unv = $this->app->loginUser();
        $this->db->run('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$unv['id']]);
        $msgs = [];
        foreach ([[$u['email'], 'falsch'], ['niemand@test.invalid', 'egal'], [$unv['email'], 'Korrektes-Pferd-42']] as [$e, $p]) {
            $ex = $this->assertThrows(AuthException::class, fn () => $this->app->auth->login($e, $p, '203.0.113.6'));
            $msgs[] = $ex->getMessage();
        }
        $this->assertSame($msgs[0], $msgs[1]);
        $this->assertSame($msgs[0], $msgs[2]);
    }

    public function testDisabledAccountCannotLogin(): void
    {
        $u = $this->app->loginUser();
        $this->db->run("UPDATE users SET status = 'disabled' WHERE id = ?", [$u['id']]);
        $this->assertThrows(AuthException::class, fn () => $this->app->auth->login($u['email'], 'Korrektes-Pferd-42', '203.0.113.7'));
    }

    public function testAccountLocksAfterRepeatedFailuresAndUnlocksLater(): void
    {
        $u = $this->app->loginUser();
        for ($i = 0; $i < AuthService::MAX_FAILS; $i++) {
            $this->assertThrows(AuthException::class, fn () => $this->app->auth->login($u['email'], 'falsch', '203.0.113.' . (10 + $i)));
        }
        // auch das richtige Passwort wird jetzt abgewiesen
        $this->assertThrows(AuthException::class, fn () => $this->app->auth->login($u['email'], 'Korrektes-Pferd-42', '203.0.113.50'));
        $this->app->clock->advance(AuthService::LOCK_MINUTES * 60 + 5);
        $this->assertSame('ok', $this->app->auth->login($u['email'], 'Korrektes-Pferd-42', '203.0.113.50')['next']);
        $this->assertTrue((int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auth.account_locked' AND object_id = ?", [$u['id']]) >= 1);
    }

    public function testIpRateLimitBlocksBruteForceAcrossAccounts(): void
    {
        $hit = false;
        for ($i = 0; $i < 60; $i++) {
            try {
                $this->app->auth->login('x' . $i . '@test.invalid', 'egal', '198.51.100.9');
            } catch (TooManyRequestsException) {
                $hit = true;
                break;
            } catch (AuthException) {
            }
        }
        $this->assertTrue($hit, 'Rate-Limit greift');
    }

    public function testPasswordPolicy(): void
    {
        $this->assertThrows(ValidationException::class, fn () => PasswordPolicy::assertAcceptable('kurz'));
        $this->assertThrows(ValidationException::class, fn () => PasswordPolicy::assertAcceptable('aaaaaaaaaaaaaaaa'));
        $this->assertThrows(ValidationException::class, fn () => PasswordPolicy::assertAcceptable('anna.muster-geheim', 'anna.muster@x.ch'));
        PasswordPolicy::assertAcceptable('Korrektes-Pferd-42', 'a@b.ch');
        $this->assertTrue(true);
    }

    public function testMfaEnrollmentLoginReplayAndRecoveryCodes(): void
    {
        $u = $this->app->loginUser();
        $setup = $this->app->auth->beginMfaEnrollment($u['id']);
        $t = $this->app->clock->now()->getTimestamp();
        $code = Totp::code($setup['secret'], Totp::counterAt($t));
        $recovery = $this->app->auth->confirmMfaEnrollment($u['id'], $code);
        $this->assertSame(10, count($recovery));
        $stored = (string) $this->db->value('SELECT mfa_secret_enc FROM users WHERE id = ?', [$u['id']]);
        $this->assertFalse(str_contains($stored, $setup['secret']), 'Secret verschlüsselt gespeichert');
        $hashes = (string) $this->db->value('SELECT mfa_recovery_hashes FROM users WHERE id = ?', [$u['id']]);
        $this->assertFalse(str_contains($hashes, str_replace('-', '', $recovery[0])), 'Recovery-Codes nur als Hash');

        $this->assertSame('mfa', $this->app->auth->login($u['email'], 'Korrektes-Pferd-42', '203.0.113.20')['next']);
        // Replay: der bei der Einrichtung verwendete Code ist verbraucht
        $this->assertThrows(AuthException::class, fn () => $this->app->auth->verifyMfa($u['id'], $code));
        $this->app->clock->advance(30);
        $next = Totp::code($setup['secret'], Totp::counterAt($this->app->clock->now()->getTimestamp()));
        $this->app->auth->verifyMfa($u['id'], $next);
        $this->assertThrows(AuthException::class, fn () => $this->app->auth->verifyMfa($u['id'], $next), 'Code nur einmal');

        // Wiederherstellungscode: einmalig
        $this->app->auth->verifyMfa($u['id'], $recovery[0]);
        $this->assertThrows(AuthException::class, fn () => $this->app->auth->verifyMfa($u['id'], $recovery[0]));
        $this->app->auth->verifyMfa($u['id'], strtolower($recovery[1]));
    }

    public function testMfaBruteForceIsRateLimited(): void
    {
        $u = $this->app->loginUser();
        $s = $this->app->auth->beginMfaEnrollment($u['id']);
        $this->app->auth->confirmMfaEnrollment($u['id'], Totp::code($s['secret'], Totp::counterAt($this->app->clock->now()->getTimestamp())));
        $blocked = false;
        for ($i = 0; $i < 12; $i++) {
            try {
                $this->app->auth->verifyMfa($u['id'], '000000');
            } catch (TooManyRequestsException) {
                $blocked = true;
                break;
            } catch (AuthException) {
            }
        }
        $this->assertTrue($blocked);
    }

    public function testMfaMandatoryForTenantAdminAndSystemAdmin(): void
    {
        $t = $this->app->fx->tenant();
        $adm = $this->app->loginUser();
        $this->app->fx->member($t, $adm['id'], 'tenant_admin');
        $this->assertSame('mfa_enroll', $this->app->auth->login($adm['email'], 'Korrektes-Pferd-42', '203.0.113.30')['next']);
        $sys = $this->app->loginUser();
        $this->db->run('UPDATE users SET is_system_admin = 1 WHERE id = ?', [$sys['id']]);
        $this->assertSame('mfa_enroll', $this->app->auth->login($sys['email'], 'Korrektes-Pferd-42', '203.0.113.31')['next']);
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->disableMfa($adm['id'], 'Korrektes-Pferd-42'), 'Pflicht-MFA nicht abschaltbar');
        $mgr = $this->app->loginUser();
        $this->app->fx->member($t, $mgr['id'], 'contract_manager');
        $this->assertSame('ok', $this->app->auth->login($mgr['email'], 'Korrektes-Pferd-42', '203.0.113.32')['next']);
        $this->db->run('UPDATE tenants SET mfa_required = 1 WHERE id = ?', [$t]);
        $this->assertSame('mfa_enroll', $this->app->auth->login($mgr['email'], 'Korrektes-Pferd-42', '203.0.113.33')['next']);
    }

    private function tokenFromMail(string $marker): string
    {
        $body = $this->app->mailer->sent[count($this->app->mailer->sent) - 1]['body'];
        preg_match('#' . preg_quote($marker, '#') . '([A-Za-z0-9_-]+)#', $body, $m);
        return $m[1];
    }

    public function testPasswordResetFlowIsOneTimeAndInvalidatesSessions(): void
    {
        $u = $this->app->loginUser();
        $epoch = $this->app->auth->sessionEpoch($u['id']);
        $this->app->auth->requestPasswordReset($u['email'], '203.0.113.40');
        $this->assertSame(1, count($this->app->mailer->sent));
        $token = $this->tokenFromMail('/passwort/zuruecksetzen/');
        $stored = (string) $this->db->value('SELECT token_hash FROM auth_tokens WHERE user_id = ?', [$u['id']]);
        $this->assertFalse(str_contains($stored, $token), 'Token nur als Hash');
        $this->assertTrue($this->app->auth->resetTokenValid($token));
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->resetPassword($token, 'kurz'));
        $this->app->auth->resetPassword($token, 'Neues-Sicheres-Passwort-7');
        $this->assertTrue($this->app->auth->sessionEpoch($u['id']) > $epoch, 'alte Sitzungen ungültig');
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->resetPassword($token, 'Noch-Ein-Passwort-88'), 'Token einmalig');
        $this->app->auth->login($u['email'], 'Neues-Sicheres-Passwort-7', '203.0.113.41');
    }

    public function testResetDoesNotRevealWhetherAccountExists(): void
    {
        $this->app->auth->requestPasswordReset('gibtsnicht@test.invalid', '203.0.113.42');
        $this->assertSame(0, count($this->app->mailer->sent));
        $u = $this->app->loginUser();
        $this->app->auth->requestPasswordReset($u['email'], '203.0.113.42');
        $this->assertSame(1, count($this->app->mailer->sent));
    }

    public function testResetTokenExpires(): void
    {
        $u = $this->app->loginUser();
        $this->app->auth->requestPasswordReset($u['email'], '203.0.113.43');
        $token = $this->tokenFromMail('/passwort/zuruecksetzen/');
        $this->app->clock->advance(61 * 60);
        $this->assertFalse($this->app->auth->resetTokenValid($token));
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->resetPassword($token, 'Neues-Sicheres-Passwort-7'));
    }

    public function testResetEmailRateLimitedSilently(): void
    {
        $u = $this->app->loginUser();
        for ($i = 0; $i < 6; $i++) {
            $this->app->auth->requestPasswordReset($u['email'], '203.0.113.44');
        }
        $this->assertSame(3, count($this->app->mailer->sent));
    }

    public function testNewResetRequestInvalidatesOlderToken(): void
    {
        $u = $this->app->loginUser();
        $this->app->auth->requestPasswordReset($u['email'], '203.0.113.45');
        $old = $this->tokenFromMail('/passwort/zuruecksetzen/');
        $this->app->auth->requestPasswordReset($u['email'], '203.0.113.45');
        $this->assertFalse($this->app->auth->resetTokenValid($old));
    }

    public function testInvitationActivatesAccountAndVerifiesEmail(): void
    {
        $id = $this->app->fx->user('internal', 'pending');
        $this->app->auth->sendInvitation($id, 'Admin', 'Mandant Test AG');
        $token = $this->tokenFromMail('/einladung/');
        $this->assertTrue($this->app->auth->inviteValid($token));
        $this->app->auth->acceptInvitation($token, 'Einladung-Passwort-12');
        $row = $this->db->one('SELECT status, email_verified_at FROM users WHERE id = ?', [$id]);
        $this->assertSame('active', $row['status']);
        $this->assertTrue($row['email_verified_at'] !== null);
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->acceptInvitation($token, 'Einladung-Passwort-12'));
    }

    public function testEmailVerification(): void
    {
        $id = $this->app->fx->user();
        $this->app->auth->sendEmailVerification($id);
        $token = $this->tokenFromMail('/email/bestaetigen/');
        $this->app->auth->verifyEmail($token);
        $this->assertTrue($this->db->value('SELECT email_verified_at FROM users WHERE id = ?', [$id]) !== null);
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->verifyEmail($token));
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->verifyEmail('zu-kurz'));
    }

    public function testPasswordChangeRequiresCurrentPasswordAndKillsSessions(): void
    {
        $u = $this->app->loginUser();
        $e = $this->app->auth->sessionEpoch($u['id']);
        $this->assertThrows(ValidationException::class, fn () => $this->app->auth->changePassword($u['id'], 'falsch', 'Neues-Sicheres-Passwort-7'));
        $this->app->auth->changePassword($u['id'], 'Korrektes-Pferd-42', 'Neues-Sicheres-Passwort-7');
        $this->assertTrue($this->app->auth->sessionEpoch($u['id']) > $e);
    }

    public function testPasswordsAreStoredHashedNeverPlain(): void
    {
        $u = $this->app->loginUser();
        $h = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        $this->assertFalse(str_contains($h, 'Korrektes'));
        $this->assertTrue(str_starts_with($h, '$argon2id$') || str_starts_with($h, '$2y$'));
    }
}
