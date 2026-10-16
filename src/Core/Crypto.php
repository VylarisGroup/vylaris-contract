<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Ausschliesslich Standardprimitive (libsodium, hash_hmac, random_bytes) – keine eigene Kryptografie.
 */
final class Crypto
{
    /** Zufälliges URL-sicheres Token (Klartext geht an den Nutzer, nur der Hash wird gespeichert). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Deterministischer Hash für Token-Lookup (Tokens haben hohe Entropie, daher kein Passwort-Hash nötig). */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** Authentifizierte Verschlüsselung für Geheimnisse in der DB (z. B. MFA-Secrets, API-Tokens). */
    public static function encrypt(string $plaintext, string $key): string
    {
        $key = self::normalizeKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($plaintext);
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded, string $key): string
    {
        $key = self::normalizeKey($key);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        return $plain;
    }

    private static function normalizeKey(string $key): string
    {
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY muss 32 Byte (base64) lang sein.');
        }
        return $raw;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function sha256File(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Datei konnte nicht gehasht werden.');
        }
        return $hash;
    }
}
