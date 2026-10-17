<?php

declare(strict_types=1);

namespace App\Security\Oidc;

use App\Modules\Signatures\HttpTransport;
use App\Modules\Signatures\ProviderException;

/**
 * OpenID-Connect-Client (Authorization Code mit PKCE S256). Alle Adressen müssen https und öffentlich erreichbar sein
 * (Schutz vor Zugriffen auf interne Dienste). Der ID-Token wird vollständig geprüft: Signatur (RS256 gegen JWKS), iss, aud/azp,
 * exp, iat, nonce. Ob die Prüfung gegen einen echten Anbieter besteht, hängt von dessen Konfiguration ab (nur RS256).
 */
final class OidcClient
{
    /** @param (\Closure(string):bool)|null $hostCheck prüft, ob ein Hostname öffentlich ist (für Tests ersetzbar) */
    public function __construct(private readonly HttpTransport $http, private readonly ?\Closure $hostCheck = null, private readonly int $skew = 120)
    {
    }

    /** @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,jwks_uri:string} */
    public function discover(string $issuer): array
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        $doc = $this->getJson($url);
        if (($doc['issuer'] ?? null) !== $issuer) {
            throw new OidcException('Die Angabe «issuer» des Anbieters stimmt nicht mit der Konfiguration überein.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            $this->assertUrl((string) ($doc[$k] ?? ''));
        }
        $methods = $doc['code_challenge_methods_supported'] ?? null;
        if (is_array($methods) && !in_array('S256', $methods, true)) {
            throw new OidcException('Der Anbieter unterstützt PKCE (S256) nicht.');
        }
        return ['issuer' => $issuer, 'authorization_endpoint' => $doc['authorization_endpoint'], 'token_endpoint' => $doc['token_endpoint'], 'jwks_uri' => $doc['jwks_uri']];
    }

    public function authorizationUrl(string $endpoint, string $clientId, string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $sep = str_contains($endpoint, '?') ? '&' : '?';
        return $endpoint . $sep . http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'scope' => 'openid email profile',
            'state' => $state, 'nonce' => $nonce, 'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Tauscht den Code gegen Token und liefert die geprüften Claims des ID-Tokens.
     *
     * @param array{issuer:string,token_endpoint:string,jwks_uri:string} $disc
     * @return array<string,mixed>
     */
    public function exchange(array $disc, string $clientId, ?string $secret, string $redirectUri, string $code, string $verifier, string $nonce, int $now): array
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier];
        if ($secret !== null && $secret !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($secret));
        } else {
            $form['client_id'] = $clientId;
        }
        $this->assertUrl($disc['token_endpoint']);
        try {
            $res = $this->http->request('POST', $disc['token_endpoint'], $headers, http_build_query($form));
        } catch (ProviderException) {
            throw new OidcException('Der Anbieter ist nicht erreichbar.');
        }
        $tok = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($tok) || !is_string($tok['id_token'] ?? null)) {
            throw new OidcException('Der Anbieter hat die Anmeldung abgelehnt.');
        }
        $jwks = $this->getJson($disc['jwks_uri']);
        $keys = is_array($jwks['keys'] ?? null) ? array_values(array_filter($jwks['keys'], 'is_array')) : [];
        $p = Jwt::parse($tok['id_token']);
        if (!Jwt::verifyRs256($p, $keys)) {
            throw new OidcException('Die Signatur des ID-Tokens ist ungültig.');
        }
        return $this->validateClaims($p['claims'], $disc['issuer'], $clientId, $nonce, $now);
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function validateClaims(array $c, string $issuer, string $clientId, string $nonce, int $now): array
    {
        if (($c['iss'] ?? null) !== $issuer) {
            throw new OidcException('Falscher Aussteller (iss).');
        }
        $aud = $c['aud'] ?? null;
        $auds = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $auds, true) || (count($auds) > 1 && ($c['azp'] ?? null) !== $clientId)) {
            throw new OidcException('Das Token ist nicht für diese Anwendung ausgestellt (aud).');
        }
        if (!is_int($c['exp'] ?? null) || $c['exp'] + $this->skew < $now) {
            throw new OidcException('Das Token ist abgelaufen.');
        }
        if (isset($c['iat']) && (!is_int($c['iat']) || $c['iat'] - $this->skew > $now)) {
            throw new OidcException('Ungültiger Ausstellungszeitpunkt.');
        }
        if (!is_string($c['nonce'] ?? null) || !hash_equals($nonce, $c['nonce'])) {
            throw new OidcException('Nonce stimmt nicht.');
        }
        if (!is_string($c['sub'] ?? null) || $c['sub'] === '' || strlen($c['sub']) > 255) {
            throw new OidcException('Kennung (sub) fehlt.');
        }
        return $c;
    }

    private function assertUrl(string $u): void
    {
        $p = parse_url($u);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($u) > 500) {
            throw new OidcException('Adressen des Anbieters müssen mit https:// beginnen.');
        }
        $ok = $this->hostCheck !== null ? ($this->hostCheck)($p['host']) : self::isPublicHost($p['host']);
        if (!$ok) {
            throw new OidcException('Der Server des Anbieters ist nicht öffentlich erreichbar (interne Adressen sind nicht erlaubt).');
        }
    }

    public static function isPublicHost(string $host): bool
    {
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function getJson(string $url): array
    {
        $this->assertUrl($url);
        try {
            $res = $this->http->request('GET', $url, ['Accept' => 'application/json'], null);
        } catch (ProviderException) {
            throw new OidcException('Der Anbieter ist nicht erreichbar.');
        }
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($j)) {
            throw new OidcException('Unerwartete Antwort des Anbieters.');
        }
        return $j;
    }
}
