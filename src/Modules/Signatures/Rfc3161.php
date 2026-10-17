<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Zeitstempel nach RFC 3161: Anfrage bauen, Antwort lesen und prüfen (Status, Hash-Abgleich, Nonce, Zeitpunkt, CMS-Signatur
 * des Tokens). NICHT geprüft wird die Zertifikatskette der Zeitstempelstelle (Vertrauensanker, Widerruf, Zeitstempel-Eignung):
 * das Token wird mit allen Zertifikaten gespeichert und kann extern geprüft werden (openssl ts -verify). Keine eigene Kryptografie:
 * die Signaturprüfung übernimmt OpenSSL (openssl_cms_verify), hier wird nur die ASN.1-Struktur gelesen.
 */
final class Rfc3161
{
    private const OID_SHA256 = "\x60\x86\x48\x01\x65\x03\x04\x02\x01";
    private const OID_TSTINFO = "\x2A\x86\x48\x86\xF7\x0D\x01\x09\x10\x01\x04";

    /** DER-Anfrage (TimeStampReq) für einen SHA-256-Wert. */
    public static function request(string $sha256Bin, int $nonce): string
    {
        if (strlen($sha256Bin) !== 32) {
            throw new \InvalidArgumentException('SHA-256 erwartet.');
        }
        $algo = self::seq(self::tlv(0x06, self::OID_SHA256) . "\x05\x00");
        $imprint = self::seq($algo . self::tlv(0x04, $sha256Bin));
        return self::seq("\x02\x01\x01" . $imprint . self::int($nonce) . "\x01\x01\xFF"); // version 1, imprint, nonce, certReq TRUE
    }

    /**
     * @return array{token:string,gen_time:\DateTimeImmutable,serial:string,policy:string,signature_ok:bool}
     * @throws ProviderException
     */
    public static function parseResponse(string $der, string $sha256Bin, int $nonce): array
    {
        try {
            $top = self::read($der, 0);
            if ($top['tag'] !== 0x30) {
                throw new \RuntimeException('Keine gültige Antwort');
            }
            $kids = self::children($der, $top);
            $status = self::children($der, $kids[0]);
            $st = (int) self::intValue($der, $status[0]);
            if (!in_array($st, [0, 1], true)) {
                throw new ProviderException('Die Zeitstempelstelle hat den Zeitstempel abgelehnt (Status ' . $st . ').', false);
            }
            if (!isset($kids[1])) {
                throw new \RuntimeException('Antwort ohne Token');
            }
            $token = substr($der, $kids[1]['start'], $kids[1]['end'] - $kids[1]['start']);
            $tst = self::tstInfo($token);
            $t = self::children($tst, self::read($tst, 0));
            // version, policy, messageImprint, serialNumber, genTime, [accuracy], [ordering], [nonce], ...
            $policy = bin2hex(substr($tst, $t[1]['cs'], $t[1]['len']));
            $mi = self::children($tst, $t[2]);
            $alg = self::children($tst, $mi[0]);
            if (substr($tst, $alg[0]['cs'], $alg[0]['len']) !== self::OID_SHA256 || substr($tst, $mi[1]['cs'], $mi[1]['len']) !== $sha256Bin) {
                throw new ProviderException('Der Zeitstempel gehört nicht zu diesem Dokument (Hash stimmt nicht).', false);
            }
            $serial = bin2hex(substr($tst, $t[3]['cs'], $t[3]['len']));
            if ($t[4]['tag'] !== 0x18 || preg_match('/^(\d{14})(\.\d+)?Z$/', substr($tst, $t[4]['cs'], $t[4]['len']), $m) !== 1) {
                throw new \RuntimeException('Zeitangabe nicht lesbar');
            }
            $time = \DateTimeImmutable::createFromFormat('!YmdHis', $m[1], new \DateTimeZone('UTC'));
            if ($time === false) {
                throw new \RuntimeException('Zeitangabe nicht lesbar');
            }
            $nonceSeen = null;
            for ($i = 5; $i < count($t); $i++) {
                if ($t[$i]['tag'] === 0x02) {
                    $nonceSeen = ltrim(bin2hex(substr($tst, $t[$i]['cs'], $t[$i]['len'])), '0');
                }
            }
            if ($nonceSeen === null || $nonceSeen !== ltrim(dechex($nonce), '0')) {
                throw new ProviderException('Die Antwort der Zeitstempelstelle passt nicht zur Anfrage (Nonce).', false);
            }
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ProviderException('Die Antwort der Zeitstempelstelle ist nicht lesbar.', false);
        }
        return ['token' => $token, 'gen_time' => $time, 'serial' => $serial, 'policy' => $policy, 'signature_ok' => self::verifySignature($token)];
    }

    /** Prüft nur die CMS-Signatur des Tokens (Zertifikat aus dem Token), nicht die Kette. */
    public static function verifySignature(string $token): bool
    {
        if (!function_exists('openssl_cms_verify')) {
            return false;
        }
        $in = tempnam(sys_get_temp_dir(), 'tsa');
        if ($in === false) {
            return false;
        }
        try {
            file_put_contents($in, $token);
            return @openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, null, null, null, OPENSSL_ENCODING_DER) === true;
        } finally {
            @unlink($in);
        }
    }

    // ------------------------------------------------------------------ DER

    private static function tstInfo(string $token): string
    {
        $p = strpos($token, "\x06\x0B" . self::OID_TSTINFO);
        if ($p === false) {
            throw new \RuntimeException('kein TSTInfo');
        }
        $a0 = self::read($token, $p + 2 + 11);
        if ($a0['tag'] !== 0xA0) {
            throw new \RuntimeException('kein eContent');
        }
        $oct = self::read($token, $a0['cs']);
        if ($oct['tag'] !== 0x04) {
            throw new \RuntimeException('kein OCTET STRING');
        }
        return substr($token, $oct['cs'], $oct['len']);
    }

    /** @return array{tag:int,start:int,cs:int,len:int,end:int} */
    private static function read(string $d, int $pos): array
    {
        $n = strlen($d);
        if ($pos + 2 > $n) {
            throw new \RuntimeException('DER-Ende');
        }
        $tag = ord($d[$pos]);
        $l = ord($d[$pos + 1]);
        $cs = $pos + 2;
        if ($l & 0x80) {
            $k = $l & 0x7F;
            if ($k < 1 || $k > 4 || $pos + 2 + $k > $n) {
                throw new \RuntimeException('DER-Länge');
            }
            $l = 0;
            for ($i = 0; $i < $k; $i++) {
                $l = ($l << 8) | ord($d[$pos + 2 + $i]);
            }
            $cs = $pos + 2 + $k;
        }
        if ($cs + $l > $n) {
            throw new \RuntimeException('DER-Überlauf');
        }
        return ['tag' => $tag, 'start' => $pos, 'cs' => $cs, 'len' => $l, 'end' => $cs + $l];
    }

    /** @param array{cs:int,end:int} $node @return list<array{tag:int,start:int,cs:int,len:int,end:int}> */
    private static function children(string $d, array $node): array
    {
        $out = [];
        $p = $node['cs'];
        while ($p < $node['end']) {
            $c = self::read($d, $p);
            $out[] = $c;
            $p = $c['end'];
        }
        return $out;
    }

    /** @param array{tag:int,cs:int,len:int} $n */
    private static function intValue(string $d, array $n): int
    {
        return $n['len'] > 0 ? (int) hexdec(bin2hex(substr($d, $n['cs'], min($n['len'], 4)))) : 0;
    }

    private static function tlv(int $tag, string $content): string
    {
        $l = strlen($content);
        $len = $l < 128 ? chr($l) : ($l < 256 ? "\x81" . chr($l) : "\x82" . chr($l >> 8) . chr($l & 0xFF));
        return chr($tag) . $len . $content;
    }

    private static function seq(string $content): string
    {
        return self::tlv(0x30, $content);
    }

    private static function int(int $v): string
    {
        $b = ltrim(pack('J', $v), "\x00");
        if ($b === '' || (ord($b[0]) & 0x80)) {
            $b = "\x00" . $b;
        }
        return self::tlv(0x02, $b);
    }
}
