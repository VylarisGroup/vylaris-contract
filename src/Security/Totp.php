<?php

declare(strict_types=1);

namespace App\Security;

/** TOTP nach RFC 6238 (HMAC-SHA1, 30 s, 6 Stellen) – kompatibel zu gängigen Authenticator-Apps. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $base32Secret, int $counter, int $digits = 6): string
    {
        $key = self::base32Decode($base32Secret);
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function counterAt(int $unixTime, int $period = 30): int
    {
        return intdiv($unixTime, $period);
    }

    /**
     * Prüft den Code im Fenster ±$window Schritte. Liefert den passenden Zähler oder null.
     * Der Aufrufer muss Zähler <= zuletzt akzeptiertem Zähler ablehnen (Replay-Schutz).
     */
    public static function verify(string $base32Secret, string $code, int $unixTime, int $window = 1): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $base = self::counterAt($unixTime);
        $found = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($base32Secret, $base + $i), $code)) {
                $found = $base + $i; // kein frühes Beenden: konstante Laufzeit
            }
        }
        return $found;
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=-]+/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Ungültiges Base32-Zeichen.');
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
