<?php

declare(strict_types=1);

namespace App\Security;

/**
 * WebAuthn/Passkeys (Level 2/3), serverseitige Prüfung ohne Fremdbibliothek.
 * Unterstützt ES256 (-7) und RS256 (-257), Attestation »none« (Herstellernachweise werden nicht ausgewertet),
 * Benutzerverifizierung wird immer verlangt (Passkey = Besitz + Biometrie/PIN).
 */
final class WebAuthn
{
    public const ALGS = [-7, -257];

    public function __construct(private readonly string $rpId, private readonly string $origin, private readonly string $rpName = 'Vertragsplattform')
    {
    }

    /** Aus einer APP_URL ableiten; null, wenn kein gültiger https-Ursprung (localhost darf http). */
    public static function fromAppUrl(string $url, string $name = 'Vertragsplattform'): ?self
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host'], $p['scheme'])) {
            return null;
        }
        $host = strtolower((string) $p['host']);
        $scheme = strtolower((string) $p['scheme']);
        $local = $host === 'localhost';
        if (($scheme !== 'https' && !($scheme === 'http' && $local)) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null; // rpId darf keine IP-Adresse sein
        }
        return new self($host, $scheme . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : ''), $name);
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    public static function b64uEncode(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        if ($d === false || $s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1) {
            throw new WebAuthnException('Ungültige Base64url-Daten.');
        }
        return $d;
    }

    public static function newChallenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    /**
     * @param list<string> $excludeIds Credential-IDs (Rohbytes) bereits registrierter Passkeys
     * @return array<string,mixed>
     */
    public function registrationOptions(string $challenge, string $userHandle, string $email, string $displayName, array $excludeIds): array
    {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
            'user' => ['id' => self::b64uEncode($userHandle), 'name' => $email, 'displayName' => $displayName],
            'pubKeyCredParams' => array_map(static fn (int $a): array => ['type' => 'public-key', 'alg' => $a], self::ALGS),
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => self::b64uEncode($id)], $excludeIds),
        ];
    }

    /** @return array<string,mixed> */
    public function authenticationOptions(string $challenge): array
    {
        return ['challenge' => $challenge, 'rpId' => $this->rpId, 'timeout' => 120000, 'userVerification' => 'required', 'allowCredentials' => []];
    }

    /**
     * @param array<string,mixed> $cred Antwort des Browsers (id, rawId, type, response{clientDataJSON, attestationObject, transports})
     * @return array{credentialId:string,publicKeyPem:string,alg:int,signCount:int,backupEligible:bool,backupState:bool,transports:string}
     */
    public function verifyRegistration(array $cred, string $expectedChallenge): array
    {
        $resp = $cred['response'] ?? null;
        if (!is_array($resp) || ($cred['type'] ?? '') !== 'public-key') {
            throw new WebAuthnException('Antwort unvollständig.');
        }
        $this->checkClientData(self::b64uDecode((string) ($resp['clientDataJSON'] ?? '')), 'webauthn.create', $expectedChallenge);
        $att = Cbor::decode(self::b64uDecode((string) ($resp['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData']) || !isset($att['fmt'])) {
            throw new WebAuthnException('Attestation ungültig.');
        }
        $ad = $this->parseAuthData($att['authData'], true);
        $this->checkAuthData($ad);
        if (!isset($ad['credentialId'], $ad['cose'])) {
            throw new WebAuthnException('Keine Anmeldedaten im Passkey.');
        }
        $id = $ad['credentialId'];
        if ($id === '' || strlen($id) > 1023 || !hash_equals($id, self::b64uDecode((string) ($cred['rawId'] ?? $cred['id'] ?? '')))) {
            throw new WebAuthnException('Credential-ID passt nicht.');
        }
        [$pem, $alg] = $this->coseToPem($ad['cose']);
        $transports = [];
        foreach ((array) ($resp['transports'] ?? []) as $t) {
            if (is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t) === 1) {
                $transports[] = $t;
            }
        }
        return ['credentialId' => $id, 'publicKeyPem' => $pem, 'alg' => $alg, 'signCount' => $ad['signCount'], 'backupEligible' => (bool) ($ad['flags'] & 0x08), 'backupState' => (bool) ($ad['flags'] & 0x10), 'transports' => implode(',', array_slice($transports, 0, 6))];
    }

    /**
     * @param array<string,mixed> $cred Antwort des Browsers (response{authenticatorData, clientDataJSON, signature})
     * @return array{signCount:int,backupState:bool}
     */
    public function verifyAssertion(array $cred, string $expectedChallenge, string $publicKeyPem, int $storedCount): array
    {
        $resp = $cred['response'] ?? null;
        if (!is_array($resp) || ($cred['type'] ?? '') !== 'public-key') {
            throw new WebAuthnException('Antwort unvollständig.');
        }
        $clientRaw = self::b64uDecode((string) ($resp['clientDataJSON'] ?? ''));
        $this->checkClientData($clientRaw, 'webauthn.get', $expectedChallenge);
        $authRaw = self::b64uDecode((string) ($resp['authenticatorData'] ?? ''));
        $sig = self::b64uDecode((string) ($resp['signature'] ?? ''));
        $ad = $this->parseAuthData($authRaw, false);
        $this->checkAuthData($ad);
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false || openssl_verify($authRaw . hash('sha256', $clientRaw, true), $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new WebAuthnException('Signatur ungültig.');
        }
        if (($ad['signCount'] > 0 || $storedCount > 0) && $ad['signCount'] <= $storedCount) {
            throw new WebAuthnException('Signaturzähler nicht gestiegen (möglicherweise geklonter Authenticator).');
        }
        return ['signCount' => $ad['signCount'], 'backupState' => (bool) ($ad['flags'] & 0x10)];
    }

    private function checkClientData(string $raw, string $type, string $expectedChallenge): void
    {
        $c = json_decode($raw, true);
        if (!is_array($c) || ($c['type'] ?? '') !== $type) {
            throw new WebAuthnException('Falscher Vorgangstyp.');
        }
        if (!is_string($c['challenge'] ?? null) || !hash_equals($expectedChallenge, $c['challenge'])) {
            throw new WebAuthnException('Challenge stimmt nicht.');
        }
        if (($c['origin'] ?? '') !== $this->origin) {
            throw new WebAuthnException('Ursprung stimmt nicht (' . mb_substr((string) ($c['origin'] ?? ''), 0, 80) . ').');
        }
        if (($c['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('Fremder Frame nicht erlaubt.');
        }
    }

    /** @param array{rpIdHash:string,flags:int} $ad */
    private function checkAuthData(array $ad): void
    {
        if (!hash_equals(hash('sha256', $this->rpId, true), $ad['rpIdHash'])) {
            throw new WebAuthnException('RP-ID stimmt nicht.');
        }
        if (($ad['flags'] & 0x01) === 0) {
            throw new WebAuthnException('Anwesenheit (UP) fehlt.');
        }
        if (($ad['flags'] & 0x04) === 0) {
            throw new WebAuthnException('Benutzerverifizierung (UV) fehlt.');
        }
        if (($ad['flags'] & 0x10) !== 0 && ($ad['flags'] & 0x08) === 0) {
            throw new WebAuthnException('Ungültige Sicherungs-Flags.');
        }
    }

    /** @return array{rpIdHash:string,flags:int,signCount:int,credentialId?:string,cose?:array<int|string,mixed>} */
    private function parseAuthData(string $d, bool $needAttested): array
    {
        if (strlen($d) < 37) {
            throw new WebAuthnException('authenticatorData zu kurz.');
        }
        $out = ['rpIdHash' => substr($d, 0, 32), 'flags' => ord($d[32]), 'signCount' => (int) unpack('N', substr($d, 33, 4))[1]];
        $has = ($out['flags'] & 0x40) !== 0;
        if ($needAttested && !$has) {
            throw new WebAuthnException('Keine Anmeldedaten enthalten.');
        }
        if ($has) {
            $o = 37 + 16; // AAGUID überspringen
            if (strlen($d) < $o + 2) {
                throw new WebAuthnException('authenticatorData zu kurz.');
            }
            $len = (int) unpack('n', substr($d, $o, 2))[1];
            $o += 2;
            if ($len > 1023 || strlen($d) < $o + $len) {
                throw new WebAuthnException('Credential-ID ungültig.');
            }
            $out['credentialId'] = substr($d, $o, $len);
            $o += $len;
            $cose = Cbor::decode($d, $o);
            if (!is_array($cose)) {
                throw new WebAuthnException('Öffentlicher Schlüssel ungültig.');
            }
            $out['cose'] = $cose;
        }
        return $out;
    }

    /**
     * @param array<int|string,mixed> $c
     * @return array{0:string,1:int}
     */
    private function coseToPem(array $c): array
    {
        $alg = $c[3] ?? null;
        if ($alg === -7 && ($c[1] ?? null) === 2 && ($c[-1] ?? null) === 1 && is_string($c[-2] ?? null) && is_string($c[-3] ?? null) && strlen($c[-2]) === 32 && strlen($c[-3]) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $c[-2] . $c[-3];
        } elseif ($alg === -257 && ($c[1] ?? null) === 3 && is_string($c[-1] ?? null) && is_string($c[-2] ?? null) && strlen($c[-1]) >= 256 && strlen($c[-1]) <= 1024) {
            $rsa = self::der(0x30, self::derInt($c[-1]) . self::derInt($c[-2]));
            $algId = self::der(0x30, hex2bin('06092a864886f70d0101010500'));
            $der = self::der(0x30, $algId . self::der(0x03, "\x00" . $rsa));
        } else {
            throw new WebAuthnException('Schlüsselverfahren nicht unterstützt.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new WebAuthnException('Öffentlicher Schlüssel unbrauchbar.');
        }
        return [$pem, (int) $alg];
    }

    private static function der(int $tag, string $body): string
    {
        $l = strlen($body);
        $len = $l < 128 ? chr($l) : (($l < 256) ? "\x81" . chr($l) : "\x82" . pack('n', $l));
        return chr($tag) . $len . $body;
    }

    private static function derInt(string $b): string
    {
        $b = ltrim($b, "\x00");
        if ($b === '' || (ord($b[0]) & 0x80) !== 0) {
            $b = "\x00" . $b;
        }
        return self::der(0x02, $b);
    }
}
