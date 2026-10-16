<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AuthException;
use App\Core\ValidationException;
use App\Security\Cbor;
use App\Security\PasskeyService;
use App\Security\RateLimiter;
use App\Security\WebAuthn;
use App\Security\WebAuthnException;
use Tests\Support\TestApp;
use Tests\TestCase;

/** Passkeys mit einem simulierten Authenticator (echte ECDSA-/RSA-Signaturen, echte CBOR-Strukturen). */
/** Textstring-Marker für den Test-CBOR-Kodierer. */
final class Txt
{
    public function __construct(public string $s)
    {
    }
}

final class PasskeyTest extends TestCase
{
    private const RP = 'vertrag.example';
    private const ORIGIN = 'https://vertrag.example';

    private TestApp $app;
    private PasskeyService $svc;
    private string $appKey;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->appKey = \App\Core\Crypto::generateKey();
        $this->svc = new PasskeyService($this->db, $this->app->clock, $this->app->audit, new RateLimiter($this->db, $this->app->clock), new WebAuthn(self::RP, self::ORIGIN), $this->appKey);
    }

    // ---- Simulierter Authenticator ----

    private static function cbor(mixed $v): string
    {
        if (is_int($v)) {
            return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
        }
        if (is_string($v)) {
            return self::head(2, strlen($v)) . $v; // Bytes
        }
        if ($v instanceof Txt) {
            return self::head(3, strlen($v->s)) . $v->s;
        }
        $out = self::head(5, count($v));
        foreach ($v as $k => $x) {
            $out .= (is_int($k) ? self::cbor($k) : self::cbor(new Txt($k))) . (is_array($x) || is_int($x) || is_string($x) || $x instanceof Txt ? self::cbor($x) : '');
        }
        return $out;
    }

    private static function head(int $major, int $n): string
    {
        return $n < 24 ? chr($major << 5 | $n) : ($n < 256 ? chr($major << 5 | 24) . chr($n) : ($n < 65536 ? chr($major << 5 | 25) . pack('n', $n) : chr($major << 5 | 26) . pack('N', $n)));
    }

    /** @return array{key:\OpenSSLAsymmetricKey,alg:int,cose:string} */
    private function newKey(int $alg = -7): array
    {
        if ($alg === -7) {
            $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $d = openssl_pkey_get_details($k)['ec'];
            $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)]);
        } else {
            $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $d = openssl_pkey_get_details($k)['rsa'];
            $cose = self::cbor([1 => 3, 3 => -257, -1 => $d['n'], -2 => $d['e']]);
        }
        return ['key' => $k, 'alg' => $alg, 'cose' => $cose];
    }

    private function clientData(string $type, string $challenge, string $origin = self::ORIGIN): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
    }

    /** @param array{key:\OpenSSLAsymmetricKey,alg:int,cose:string} $k @return array<string,mixed> */
    private function registration(array $k, string $challenge, string $credId, int $flags = 0x45, string $rp = self::RP, string $origin = self::ORIGIN): array
    {
        $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $k['cose'];
        $att = self::cbor(['fmt' => new Txt('none'), 'attStmt' => [], 'authData' => $auth]);
        return ['id' => WebAuthn::b64uEncode($credId), 'rawId' => WebAuthn::b64uEncode($credId), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => WebAuthn::b64uEncode($this->clientData('webauthn.create', $challenge, $origin)),
            'attestationObject' => WebAuthn::b64uEncode($att), 'transports' => ['internal', 'hybrid'],
        ]];
    }

    /** @param array{key:\OpenSSLAsymmetricKey,alg:int,cose:string} $k @return array<string,mixed> */
    private function assertion(array $k, string $challenge, string $credId, int $uid, int $count, int $flags = 0x05, string $rp = self::RP, string $origin = self::ORIGIN): array
    {
        $auth = hash('sha256', $rp, true) . chr($flags) . pack('N', $count);
        $client = $this->clientData('webauthn.get', $challenge, $origin);
        openssl_sign($auth . hash('sha256', $client, true), $sig, $k['key'], OPENSSL_ALGO_SHA256);
        return ['id' => WebAuthn::b64uEncode($credId), 'rawId' => WebAuthn::b64uEncode($credId), 'type' => 'public-key', 'response' => [
            'authenticatorData' => WebAuthn::b64uEncode($auth), 'clientDataJSON' => WebAuthn::b64uEncode($client), 'signature' => WebAuthn::b64uEncode($sig),
            'userHandle' => WebAuthn::b64uEncode($this->svc->userHandle($uid)),
        ]];
    }

    /** Registriert einen Passkey und gibt Benutzer + Schlüssel zurück. @return array{uid:int,k:array{key:\OpenSSLAsymmetricKey,alg:int,cose:string},cid:string} */
    private function enroll(int $alg = -7): array
    {
        $u = $this->app->loginUser();
        $b = $this->svc->beginRegistration($u['id'], 'Korrektes-Pferd-42');
        $k = $this->newKey($alg);
        $cid = random_bytes(32);
        $this->svc->finishRegistration($u['id'], $this->registration($k, $b['challenge'], $cid), $b['challenge'], 'Testgerät', '203.0.113.5');
        return ['uid' => $u['id'], 'k' => $k, 'cid' => $cid];
    }

    // ---- Tests ----

    public function testRegisterAndSignInWithEs256(): void
    {
        $e = $this->enroll();
        $this->assertSame(1, $this->svc->count($e['uid']));
        $this->assertSame('Testgerät', $this->svc->list($e['uid'])[0]['name']);
        $login = $this->svc->beginLogin('203.0.113.6');
        $this->assertSame(self::RP, $login['options']['rpId']);
        $this->assertSame('required', $login['options']['userVerification']);
        $uid = $this->svc->finishLogin($this->assertion($e['k'], $login['challenge'], $e['cid'], $e['uid'], 1), $login['challenge'], '203.0.113.6');
        $this->assertSame($e['uid'], $uid);
        $this->assertSame(1, (int) $this->db->value('SELECT sign_count FROM passkeys WHERE user_id = ?', [$e['uid']]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auth.passkey_login' AND outcome = 'success'"));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auth.passkey_registered' AND outcome = 'success'"));
    }

    public function testRegisterAndSignInWithRs256(): void
    {
        $e = $this->enroll(-257);
        $login = $this->svc->beginLogin('203.0.113.7');
        $this->assertSame($e['uid'], $this->svc->finishLogin($this->assertion($e['k'], $login['challenge'], $e['cid'], $e['uid'], 1), $login['challenge'], '203.0.113.7'));
    }

    public function testAttacksAreRejected(): void
    {
        $e = $this->enroll();
        $try = function (callable $mutate, string $why) use ($e): void {
            $login = $this->svc->beginLogin('203.0.113.8');
            $cred = $mutate($e, $login['challenge']);
            $this->assertThrows(AuthException::class, fn () => $this->svc->finishLogin($cred, $login['challenge'], '203.0.113.8'), $why);
        };
        $try(fn ($e, $c) => $this->assertion($e['k'], 'falsche-challenge', $e['cid'], $e['uid'], 1), 'falsche Challenge');
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, $e['cid'], $e['uid'], 1, 0x05, self::RP, 'https://evil.example'), 'falscher Ursprung (Phishing)');
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, $e['cid'], $e['uid'], 1, 0x05, 'evil.example'), 'falsche RP-ID');
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, $e['cid'], $e['uid'], 1, 0x01), 'ohne Benutzerverifizierung');
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, $e['cid'], $e['uid'], 1, 0x04), 'ohne Anwesenheit');
        $try(fn ($e, $c) => $this->assertion($this->newKey(), $c, $e['cid'], $e['uid'], 1), 'Signatur mit fremdem Schlüssel');
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, random_bytes(32), $e['uid'], 1), 'unbekannte Credential-ID');
        $other = $this->app->loginUser();
        $try(fn ($e, $c) => $this->assertion($e['k'], $c, $e['cid'], $other['id'], 1), 'Benutzerkennung eines anderen Kontos');
        $try(function ($e, $c) { $a = $this->assertion($e['k'], $c, $e['cid'], $e['uid'], 1); $a['response']['signature'] = WebAuthn::b64uEncode(random_bytes(70)); return $a; }, 'manipulierte Signatur');
        $this->assertSame(0, (int) $this->db->value('SELECT sign_count FROM passkeys WHERE user_id = ?', [$e['uid']]));
    }

    public function testReplayAndClonedCounterAreRejected(): void
    {
        $e = $this->enroll();
        $l1 = $this->svc->beginLogin('203.0.113.9');
        $a1 = $this->assertion($e['k'], $l1['challenge'], $e['cid'], $e['uid'], 5);
        $this->svc->finishLogin($a1, $l1['challenge'], '203.0.113.9');
        $l2 = $this->svc->beginLogin('203.0.113.9');
        $this->assertThrows(AuthException::class, fn () => $this->svc->finishLogin($this->assertion($e['k'], $l2['challenge'], $e['cid'], $e['uid'], 5), $l2['challenge'], '203.0.113.9'), 'gleicher Zähler');
        $this->assertThrows(AuthException::class, fn () => $this->svc->finishLogin($this->assertion($e['k'], $l2['challenge'], $e['cid'], $e['uid'], 3), $l2['challenge'], '203.0.113.9'), 'kleinerer Zähler');
        $this->assertSame($e['uid'], $this->svc->finishLogin($this->assertion($e['k'], $l2['challenge'], $e['cid'], $e['uid'], 6), $l2['challenge'], '203.0.113.9'));
    }

    public function testSuspendedOrUnverifiedAccountCannotUsePasskey(): void
    {
        $e = $this->enroll();
        $this->db->run("UPDATE users SET status = 'disabled' WHERE id = ?", [$e['uid']]);
        $l = $this->svc->beginLogin('203.0.113.10');
        $this->assertThrows(AuthException::class, fn () => $this->svc->finishLogin($this->assertion($e['k'], $l['challenge'], $e['cid'], $e['uid'], 1), $l['challenge'], '203.0.113.10'));
    }

    public function testRegistrationNeedsPasswordAndChecksAttestation(): void
    {
        $u = $this->app->loginUser();
        $this->assertThrows(ValidationException::class, fn () => $this->svc->beginRegistration($u['id'], 'falsch'));
        $b = $this->svc->beginRegistration($u['id'], 'Korrektes-Pferd-42');
        $this->assertSame('required', $b['options']['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $b['options']['attestation']);
        $k = $this->newKey();
        foreach ([
            'falsche Challenge' => fn () => $this->registration($k, 'x', random_bytes(32)),
            'ohne UV' => fn () => $this->registration($k, $b['challenge'], random_bytes(32), 0x41),
            'falsche RP-ID' => fn () => $this->registration($k, $b['challenge'], random_bytes(32), 0x45, 'evil.example'),
            'falscher Ursprung' => fn () => $this->registration($k, $b['challenge'], random_bytes(32), 0x45, self::RP, 'https://evil.example'),
        ] as $why => $make) {
            $this->assertThrows(ValidationException::class, fn () => $this->svc->finishRegistration($u['id'], $make(), $b['challenge'], 'x', '203.0.113.11'), $why);
        }
        $this->assertSame(0, $this->svc->count($u['id']));
        $cid = random_bytes(32);
        $cred = $this->registration($k, $b['challenge'], $cid);
        $this->svc->finishRegistration($u['id'], $cred, $b['challenge'], '', '203.0.113.11');
        $this->assertTrue(str_starts_with($this->svc->list($u['id'])[0]['name'], 'Passkey vom '));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->finishRegistration($u['id'], $cred, $b['challenge'], 'doppelt', '203.0.113.11'), 'doppelt');
    }

    public function testLimitRemovalAndAdminReset(): void
    {
        $e = $this->enroll();
        for ($i = 1; $i < PasskeyService::MAX_PER_USER; $i++) {
            $b = $this->svc->beginRegistration($e['uid'], 'Korrektes-Pferd-42');
            $this->svc->finishRegistration($e['uid'], $this->registration($this->newKey(), $b['challenge'], random_bytes(32)), $b['challenge'], 'k' . $i, '203.0.113.12');
        }
        $this->assertSame(PasskeyService::MAX_PER_USER, $this->svc->count($e['uid']));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->beginRegistration($e['uid'], 'Korrektes-Pferd-42'));
        $id = (int) $this->svc->list($e['uid'])[0]['id'];
        $stranger = $this->app->loginUser();
        $this->svc->remove($stranger['id'], $id);
        $this->assertSame(PasskeyService::MAX_PER_USER, $this->svc->count($e['uid']), 'fremdes Konto kann nicht löschen');
        $this->svc->remove($e['uid'], $id);
        $this->assertSame(PasskeyService::MAX_PER_USER - 1, $this->svc->count($e['uid']));
        $this->app->auth->adminResetMfa($e['uid'], $stranger['id'], null);
        $this->assertSame(0, $this->svc->count($e['uid']));
    }

    public function testCborAndOriginRules(): void
    {
        $this->assertSame([1 => 2, -1 => 'ab', 'k' => [1, -3, true, null]], Cbor::decode(hex2bin('a3' . '01' . '02' . '20' . '426162' . '616b' . '84' . '01' . '22' . 'f5' . 'f6')));
        foreach (['9f01ff', 'fb3ff0000000000000', '5a00ffffff', ''] as $bad) {
            $this->assertThrows(WebAuthnException::class, fn () => Cbor::decode($bad === '' ? '' : hex2bin($bad)), 'CBOR ' . $bad);
        }
        $this->assertSame('vertrag.example', WebAuthn::fromAppUrl('https://vertrag.example')->rpId());
        $this->assertSame('localhost', WebAuthn::fromAppUrl('http://localhost:8080')->rpId());
        foreach (['http://vertrag.example', 'https://203.0.113.1', '', 'ftp://x.example'] as $bad) {
            $this->assertSame(null, WebAuthn::fromAppUrl($bad), $bad);
        }
    }
}
