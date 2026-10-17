<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Modules\Signatures\HttpTransport;
use App\Modules\Sso\SsoService;
use App\Security\Oidc\Jwt;
use App\Security\Oidc\OidcClient;
use App\Security\Oidc\OidcException;
use Tests\Support\TestApp;
use Tests\TestCase;

/** Gegen einen simulierten Identitätsanbieter mit echten RSA-Signaturen. */
final class SsoTest extends TestCase
{
    private const ISS = 'https://idp.example.test';
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $admin;
    private SsoService $sso;
    private \OpenSSLAsymmetricKey $key;
    private \OpenSSLAsymmetricKey $otherKey;
    /** @var array<string,mixed> */
    public array $claims = [];
    public string $alg = 'RS256';
    public bool $signWithOther = false;
    public ?string $seenVerifier = null;
    public ?string $seenAuth = null;
    private string $lastNonce = '';
    private string $lastChallenge = '';

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->admin = $this->app->actor($this->tenant, 'tenant_admin');
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->sso = $this->service(fn () => true);
        $this->sso->save($this->admin, ['issuer' => self::ISS, 'client_id' => 'cid', 'client_secret' => 'geheim-123', 'default_role' => 'reader', 'allowed_domains' => 'firma.test', 'enabled' => '1', 'auto_provision' => '']);
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function service(\Closure $hostCheck): SsoService
    {
        $t = $this;
        $http = new class ($t) implements HttpTransport {
            public function __construct(private readonly SsoTest $t)
            {
            }

            public function request(string $method, string $url, array $headers, ?string $body): array
            {
                return $this->t->idp($method, $url, $headers, $body);
            }
        };
        return new SsoService($this->db, $this->app->ac, $this->app->audit, $this->app->clock, $this->app->limiter, new OidcClient($http, $hostCheck), base64_encode(str_repeat('k', 32)));
    }

    /** @param array<string,string> $headers @return array{status:int,body:string} */
    public function idp(string $method, string $url, array $headers, ?string $body): array
    {
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return ['status' => 200, 'body' => json_encode(['issuer' => self::ISS, 'authorization_endpoint' => self::ISS . '/auth', 'token_endpoint' => self::ISS . '/token', 'jwks_uri' => self::ISS . '/jwks', 'code_challenge_methods_supported' => ['S256']])];
        }
        if (str_ends_with($url, '/jwks')) {
            $d = openssl_pkey_get_details($this->key);
            return ['status' => 200, 'body' => json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => self::b64($d['rsa']['n']), 'e' => self::b64($d['rsa']['e'])]]])];
        }
        if (str_ends_with($url, '/token')) {
            parse_str((string) $body, $f);
            $this->seenVerifier = $f['code_verifier'] ?? null;
            $this->seenAuth = $headers['Authorization'] ?? null;
            return ['status' => 200, 'body' => json_encode(['id_token' => $this->jwt($this->claims), 'token_type' => 'Bearer'])];
        }
        return ['status' => 404, 'body' => ''];
    }

    private static function b64(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $claims */
    private function jwt(array $claims): string
    {
        $h = self::b64(json_encode(['alg' => $this->alg, 'kid' => 'k1', 'typ' => 'JWT']));
        $p = self::b64(json_encode($claims));
        $sig = '';
        if ($this->alg === 'RS256') {
            openssl_sign("$h.$p", $sig, $this->signWithOther ? $this->otherKey : $this->key, OPENSSL_ALGO_SHA256);
        }
        return "$h.$p." . self::b64($sig === '' ? 'x' : $sig);
    }

    /** Führt Start und Rückkehr durch. @param array<string,mixed> $over @return array{user_id:int,tenant_id:int} */
    private function login(array $over = [], ?SsoService $svc = null): array
    {
        $svc ??= $this->sso;
        $b = $svc->begin($this->tenant, 'https://app.test', '203.0.113.9', '/');
        parse_str((string) parse_url($b['url'], PHP_URL_QUERY), $q);
        $this->lastNonce = $q['nonce'];
        $this->lastChallenge = $q['code_challenge'];
        $now = $this->app->clock->now()->getTimestamp();
        $this->claims = $over + ['iss' => self::ISS, 'aud' => 'cid', 'sub' => 'sub-1', 'exp' => $now + 600, 'iat' => $now, 'nonce' => $q['nonce'], 'email' => 'x@firma.test', 'email_verified' => true, 'name' => 'Neue Person'];
        return $svc->finish($b['state'], $q['state'], 'code123', 'https://app.test', '203.0.113.9');
    }

    private function member(string $email, string $role = 'reader'): int
    {
        $uid = $this->app->fx->user();
        $this->db->run('UPDATE users SET email = ? WHERE id = ?', [$email, $uid]);
        $this->app->fx->member($this->tenant, $uid, $role);
        return $uid;
    }

    private function denied(callable $fn, string $why): void
    {
        $this->app->limiter->clear(\App\Security\RateLimiter::bucket('sso_fail', '203.0.113.9'));
        $this->assertThrows(OidcException::class, $fn, $why);
    }

    public function testPkceAndLinkByVerifiedEmail(): void
    {
        $uid = $this->member('anna@firma.test');
        $r = $this->login(['email' => 'anna@firma.test', 'sub' => 'abc']);
        $this->assertSame($uid, $r['user_id']);
        $this->assertSame($this->tenant, $r['tenant_id']);
        $this->assertSame($this->lastChallenge, OidcClient::challenge((string) $this->seenVerifier), 'PKCE: Verifier passt zur Challenge');
        $this->assertTrue(str_starts_with((string) $this->seenAuth, 'Basic '), 'Geheimnis per Basic-Auth');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM user_identities WHERE user_id = ?', [$uid]));
        // zweite Anmeldung über die Verknüpfung, auch wenn die E-Mail beim Anbieter wechselt
        $r2 = $this->login(['sub' => 'abc', 'email' => 'andere@firma.test', 'email_verified' => false]);
        $this->assertSame($uid, $r2['user_id']);
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'sso.login' AND outcome = 'success'"));
    }

    public function testTokenChecksRejectBadTokens(): void
    {
        $this->member('b@firma.test');
        $bad = [
            'falscher Aussteller' => ['iss' => 'https://evil.test'],
            'falsche Audience' => ['aud' => 'andere'],
            'abgelaufen' => ['exp' => 1],
            'falsche Nonce' => ['nonce' => 'x'],
            'ohne sub' => ['sub' => ''],
        ];
        foreach ($bad as $why => $over) {
            $over += ['email' => 'b@firma.test'];
            $this->denied(fn () => $this->login($over), $why);
        }
        $this->signWithOther = true;
        $this->denied(fn () => $this->login(['email' => 'b@firma.test']), 'fremde Signatur');
        $this->signWithOther = false;
        foreach (['none', 'HS256'] as $alg) {
            $this->alg = $alg;
            $this->denied(fn () => $this->login(['email' => 'b@firma.test']), 'Algorithmus ' . $alg);
        }
        $this->alg = 'RS256';
        $this->denied(fn () => $this->login(['email' => 'b@firma.test', 'email_verified' => false]), 'unbestätigte E-Mail');
        $this->denied(fn () => $this->login(['email' => 'b@firma.test', 'email_verified' => 'true']), 'E-Mail-Bestätigung nur als echter Wahrheitswert');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM user_identities'));
        $this->app->limiter->clear(\App\Security\RateLimiter::bucket('sso_fail', '203.0.113.9'));
        $r = $this->login(['email' => 'b@firma.test']); // gültig geht
        $this->assertTrue($r['user_id'] > 0);
    }

    public function testStateAndExpiry(): void
    {
        $b = $this->sso->begin($this->tenant, 'https://app.test', '203.0.113.9', '/');
        $this->denied(fn () => $this->sso->finish($b['state'], 'falsch', 'c', 'https://app.test', '203.0.113.9'), 'State');
        $this->denied(fn () => $this->sso->finish([], 's', 'c', 'https://app.test', '203.0.113.9'), 'ohne Sitzungszustand');
        $this->app->clock->advance(700);
        parse_str((string) parse_url($b['url'], PHP_URL_QUERY), $q);
        $this->denied(fn () => $this->sso->finish($b['state'], $q['state'], 'c', 'https://app.test', '203.0.113.9'), 'abgelaufen');
    }

    public function testNoAccessWithoutMembershipAndRestrictedAccounts(): void
    {
        $other = $this->app->fx->user();
        $this->db->run('UPDATE users SET email = ? WHERE id = ?', ['fremd@firma.test', $other]); // Mitglied eines anderen Mandanten
        $this->app->fx->member($this->app->fx->tenant('Anderer'), $other, 'reader');
        $this->denied(fn () => $this->login(['email' => 'fremd@firma.test']), 'Nicht-Mitglied');
        $this->denied(fn () => $this->login(['email' => 'neu@firma.test', 'sub' => 'n1']), 'Auto-Anlage aus');

        $sys = $this->member('sys@firma.test');
        $this->db->run('UPDATE users SET is_system_admin = 1 WHERE id = ?', [$sys]);
        $this->denied(fn () => $this->login(['email' => 'sys@firma.test', 'sub' => 's1']), 'Systemadministrator');

        $dis = $this->member('weg@firma.test');
        $this->db->run("UPDATE users SET status = 'disabled' WHERE id = ?", [$dis]);
        $this->denied(fn () => $this->login(['email' => 'weg@firma.test', 'sub' => 'd1']), 'gesperrt');

        $this->denied(fn () => $this->login(['email' => 'x@fremd.test', 'sub' => 'f1']), 'Domain nicht erlaubt');
    }

    public function testAutoProvisioningOnlyWhenAllowed(): void
    {
        $this->sso->save($this->admin, ['issuer' => self::ISS, 'client_id' => 'cid', 'default_role' => 'approver', 'allowed_domains' => 'firma.test', 'enabled' => '1', 'auto_provision' => '1']);
        $r = $this->login(['email' => 'Neu@Firma.test', 'sub' => 'neu-1']);
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
        $this->assertSame('neu@firma.test', $u['email']);
        $this->assertSame('active', $u['status']);
        $this->assertNull($u['password_hash'], 'kein Passwort');
        $role = $this->db->value('SELECT r.code FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.user_id = ?', [$r['user_id']]);
        $this->assertSame('approver', $role);
        $this->denied(fn () => $this->login(['email' => 'jemand@fremd.test', 'sub' => 'x2']), 'andere Domain trotz Auto-Anlage');
    }

    public function testConfigValidationAndSecretHandling(): void
    {
        $enc = (string) $this->db->value('SELECT client_secret_enc FROM tenant_sso WHERE tenant_id = ?', [$this->tenant]);
        $this->assertTrue($enc !== '' && !str_contains($enc, 'geheim-123'), 'Geheimnis verschlüsselt');
        $this->sso->save($this->admin, ['issuer' => self::ISS, 'client_id' => 'cid2', 'default_role' => 'reader', 'allowed_domains' => '', 'enabled' => '1']);
        $this->assertSame($enc, (string) $this->db->value('SELECT client_secret_enc FROM tenant_sso WHERE tenant_id = ?', [$this->tenant]), 'leeres Geheimnis = unverändert');
        $cfg = $this->sso->config($this->admin);
        $this->assertFalse(array_key_exists('client_secret_enc', $cfg), 'Geheimnis wird nie ausgegeben');

        foreach ([
            ['issuer' => 'http://idp.example.test'],
            ['issuer' => self::ISS . '?x=1'],
            ['default_role' => 'tenant_admin'],
            ['allowed_domains' => 'kein domain!'],
            ['auto_provision' => '1', 'allowed_domains' => ''],
        ] as $bad) {
            $this->assertThrows(ValidationException::class, fn () => $this->sso->save($this->admin, $bad + ['issuer' => self::ISS, 'client_id' => 'c', 'default_role' => 'reader', 'allowed_domains' => '', 'enabled' => '0']));
        }
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(\App\Core\ForbiddenException::class, fn () => $this->sso->save($reader, ['issuer' => self::ISS, 'client_id' => 'c']));
    }

    public function testInternalHostsAreRefused(): void
    {
        $this->assertFalse(OidcClient::isPublicHost('127.0.0.1'));
        $this->assertFalse(OidcClient::isPublicHost('10.0.0.5'));
        $this->assertFalse(OidcClient::isPublicHost('169.254.169.254'));
        $this->assertFalse(OidcClient::isPublicHost('192.168.1.1'));
        $this->assertFalse(OidcClient::isPublicHost('[::1]'));
        $this->assertTrue(OidcClient::isPublicHost('93.184.216.34'));
        $strict = $this->service(fn (string $h) => OidcClient::isPublicHost($h));
        $this->assertThrows(ValidationException::class, fn () => $strict->save($this->admin, ['issuer' => 'https://127.0.0.1', 'client_id' => 'c', 'default_role' => 'reader', 'allowed_domains' => '', 'enabled' => '1']));
    }

    public function testTenantLookupAndIsolation(): void
    {
        $slug = (string) $this->db->value('SELECT slug FROM tenants WHERE id = ?', [$this->tenant]);
        $this->assertSame($this->tenant, $this->sso->tenantFor($slug));
        $this->assertSame($this->tenant, $this->sso->tenantFor('Jemand@Firma.test'));
        $this->assertNull($this->sso->tenantFor('jemand@fremd.test'));
        $this->assertNull($this->sso->tenantFor('gibtsnicht'));
        $this->sso->save($this->admin, ['issuer' => self::ISS, 'client_id' => 'cid', 'default_role' => 'reader', 'allowed_domains' => 'firma.test', 'enabled' => '0']);
        $this->assertNull($this->sso->tenantFor($slug), 'deaktiviert');
    }

    public function testFailuresAreRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->login(['aud' => 'x']);
            } catch (OidcException) {
            }
        }
        $this->assertThrows(\App\Core\TooManyRequestsException::class, fn () => $this->login());
    }

    public function testJwkToPemRoundTrip(): void
    {
        $d = openssl_pkey_get_details($this->key);
        $pem = Jwt::rsaPem(self::b64($d['rsa']['n']), self::b64($d['rsa']['e']));
        $this->assertSame(trim($d['key']), trim((string) $pem));
    }
}
