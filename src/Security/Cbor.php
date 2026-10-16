<?php

declare(strict_types=1);

namespace App\Security;

/** Minimaler CBOR-Dekoder (nur, was WebAuthn braucht: Ganzzahlen, Byte-/Textstrings, Arrays, Maps, Bool/Null). */
final class Cbor
{
    private const MAX_DEPTH = 8;

    /** @throws WebAuthnException */
    public static function decode(string $bin, int &$offset = 0, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new WebAuthnException('CBOR zu tief verschachtelt.');
        }
        if ($offset >= strlen($bin)) {
            throw new WebAuthnException('CBOR endet unerwartet.');
        }
        $ib = ord($bin[$offset++]);
        $major = $ib >> 5;
        $info = $ib & 31;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new WebAuthnException('CBOR-Typ nicht unterstützt.'),
            };
        }
        $val = self::argument($bin, $offset, $info);
        switch ($major) {
            case 0:
                return $val;
            case 1:
                return -1 - $val;
            case 2:
            case 3:
                if ($val > strlen($bin) - $offset) {
                    throw new WebAuthnException('CBOR-Länge ungültig.');
                }
                $s = substr($bin, $offset, $val);
                $offset += $val;
                return $s;
            case 4:
                if ($val > strlen($bin) - $offset) {
                    throw new WebAuthnException('CBOR-Länge ungültig.');
                }
                $a = [];
                for ($i = 0; $i < $val; $i++) {
                    $a[] = self::decode($bin, $offset, $depth + 1);
                }
                return $a;
            case 5:
                if ($val > strlen($bin) - $offset) {
                    throw new WebAuthnException('CBOR-Länge ungültig.');
                }
                $m = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = self::decode($bin, $offset, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new WebAuthnException('CBOR-Schlüssel ungültig.');
                    }
                    $m[$k] = self::decode($bin, $offset, $depth + 1);
                }
                return $m;
            default: // 6 = Tag
                return self::decode($bin, $offset, $depth + 1);
        }
    }

    private static function argument(string $bin, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $len = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new WebAuthnException('CBOR mit unbestimmter Länge nicht unterstützt.'),
        };
        if ($o + $len > strlen($bin)) {
            throw new WebAuthnException('CBOR endet unerwartet.');
        }
        $raw = substr($bin, $o, $len);
        $o += $len;
        $v = match ($len) {
            1 => ord($raw),
            2 => (int) unpack('n', $raw)[1],
            4 => (int) unpack('N', $raw)[1],
            default => (int) unpack('J', $raw)[1],
        };
        if ($v < 0) {
            throw new WebAuthnException('CBOR-Zahl zu gross.');
        }
        return $v;
    }
}
