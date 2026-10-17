<?php

declare(strict_types=1);

namespace App\Security\Oidc;

/** Minimale JWT-Prüfung: ausschliesslich RS256 (Signatur durch OpenSSL), alles andere (none, HS*, ES*) wird abgelehnt. */
final class Jwt
{
    /** @return array{header:array<string,mixed>,claims:array<string,mixed>,signing_input:string,signature:string} */
    public static function parse(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 16000) {
            throw new OidcException('Ungültiges Token.');
        }
        $h = json_decode(self::b64($parts[0]), true);
        $c = json_decode(self::b64($parts[1]), true);
        $sig = self::b64($parts[2]);
        if (!is_array($h) || !is_array($c) || $sig === '') {
            throw new OidcException('Ungültiges Token.');
        }
        return ['header' => $h, 'claims' => $c, 'signing_input' => $parts[0] . '.' . $parts[1], 'signature' => $sig];
    }

    /** @param list<array<string,mixed>> $keys JWKS-Schlüssel */
    public static function verifyRs256(array $parsed, array $keys): bool
    {
        if (($parsed['header']['alg'] ?? null) !== 'RS256') {
            throw new OidcException('Nicht unterstütztes Signaturverfahren (nur RS256).');
        }
        $kid = $parsed['header']['kid'] ?? null;
        $cands = [];
        foreach ($keys as $k) {
            if (($k['kty'] ?? '') !== 'RSA' || (isset($k['use']) && $k['use'] !== 'sig') || (isset($k['alg']) && $k['alg'] !== 'RS256')) {
                continue;
            }
            if ($kid !== null && isset($k['kid']) && $k['kid'] !== $kid) {
                continue;
            }
            $cands[] = $k;
        }
        foreach ($cands as $k) {
            $pem = self::rsaPem((string) ($k['n'] ?? ''), (string) ($k['e'] ?? ''));
            if ($pem !== null && openssl_verify($parsed['signing_input'], $parsed['signature'], $pem, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function rsaPem(string $n64, string $e64): ?string
    {
        $n = self::b64($n64);
        $e = self::b64($e64);
        if ($n === '' || $e === '' || strlen($n) < 128 || strlen($n) > 1024) {
            return null; // mind. 1024 Bit
        }
        $int = static function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || (ord($b[0]) & 0x80)) {
                $b = "\x00" . $b;
            }
            return "\x02" . self::len(strlen($b)) . $b;
        };
        $rsa = "\x30" . self::len(strlen($int($n) . $int($e))) . $int($n) . $int($e);
        $algo = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $bit = "\x03" . self::len(strlen($rsa) + 1) . "\x00" . $rsa;
        $spki = "\x30" . self::len(strlen($algo . $bit)) . $algo . $bit;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $l): string
    {
        return $l < 128 ? chr($l) : ($l < 256 ? "\x81" . chr($l) : "\x82" . chr($l >> 8) . chr($l & 0xFF));
    }

    private static function b64(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? '' : $d;
    }
}
