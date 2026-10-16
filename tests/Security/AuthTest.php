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

    public function testMfaIsVoluntaryForEveryone(): void
    {
        $base = ['id' => 0, 'is_system_admin' => 0];
        foreach (['auditor', 'notary', 'external_party', 'internal'] as $type) {
            $this->assertFalse($this->app->auth->mfaMandatory($base + ['user_type' => $type]));
        }
        $this->assertFalse($this->app->auth->mfaMandatory(['id' => 0, 'is_system_admin' => 1, 'user_type' => 'internal']));
    }

    public function testAdminsAndTenantsCanSignInWithoutSecondFactor(): void
    {
        $t = $this->app->fx->tenant();
        $adm = $this->app->loginUser();
        $this->app->fx->member($t, $adm['id'], 'tenant_admin');
        $this->assertSame('ok', $this->app->auth->login($adm['email'], 'Korrektes-Pferd-42', '203.0.113.30')['next']);
        $sys = $this->app->loginUser();
        $this->db->run('UPDATE users SET is_system_admin = 1 WHERE id = ?', [$sys['id']]);
        $this->assertSame('ok', $this->app->auth->login($sys['email'], 'Korrektes-Pferd-42', '203.0.113.31')['next']);
        $this->db->run('UPDATE tenants SET mfa_required = 1 WHERE id = ?', [$t]);
        $mgr = $this->app->loginUser();
        $this->app->fx->member($t, $mgr['id'], 'contract_manager');
        $this->assertSame('ok', $this->app->auth->login($mgr['email'], 'Korrektes-Pferd-42', '203.0.113.33')['next'], 'auch ein altes mfa_required-Flag erzwingt nichts mehr');
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
