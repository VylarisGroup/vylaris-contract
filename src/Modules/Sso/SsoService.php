<?php

declare(strict_types=1);

namespace App\Modules\Sso;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Services\AccessControl;
use App\Modules\Audit\AuditLogger;
use App\Security\Oidc\OidcClient;
use App\Security\Oidc\OidcException;
use App\Security\RateLimiter;

/**
 * Mandantenfähiges SSO über OpenID Connect. Jeder Mandant hat höchstens einen eigenen Identitätsanbieter.
 * Sicherheitsgrundsätze: SSO ersetzt nie die Passwort-/Passkey-Anmeldung; eine SSO-Sitzung gilt nur für den Mandanten, über
 * den sie entstand; Verknüpfung nur über eine vom Anbieter bestätigte E-Mail-Adresse (email_verified) bei einem bereits
 * vorhandenen Mitglied des Mandanten; Systemadministratoren und externe Konten sind ausgeschlossen; neue Konten entstehen
 * nur, wenn der Mandant es ausdrücklich erlaubt und die Domain freigegeben ist.
 */
final class SsoService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly RateLimiter $limiter,
        private readonly OidcClient $oidc,
        private readonly string $appKey,
    ) {
    }

    // ------------------------------------------------------------------ Konfiguration (Mandanten-Administration)

    /** @return array<string,mixed>|null ohne Geheimnis */
    public function config(Principal $p): ?array
    {
        $t = $this->tenant($p);
        $r = $this->db->one('SELECT tenant_id, issuer, client_id, enabled, auto_provision, default_role, allowed_domains, (client_secret_enc IS NOT NULL) AS has_secret FROM tenant_sso WHERE tenant_id = ?', [$t]);
        return $r;
    }

    /** @param array<string,string> $in issuer, client_id, client_secret, default_role, allowed_domains, enabled, auto_provision */
    public function save(Principal $p, array $in): void
    {
        $t = $this->tenant($p);
        $issuer = rtrim(trim($in['issuer'] ?? ''), '/');
        $client = trim($in['client_id'] ?? '');
        $secret = trim($in['client_secret'] ?? '');
        $role = trim($in['default_role'] ?? 'reader');
        $domains = $this->normalizeDomains($in['allowed_domains'] ?? '');
        $enabled = ($in['enabled'] ?? '') === '1' ? 1 : 0;
        $auto = ($in['auto_provision'] ?? '') === '1' ? 1 : 0;
        $err = [];
        if (!str_starts_with($issuer, 'https://') || filter_var($issuer, FILTER_VALIDATE_URL) === false || strlen($issuer) > 255 || str_contains($issuer, '?') || str_contains($issuer, '#')) {
            $err['issuer'] = 'Aussteller-Adresse (Issuer) mit https:// erforderlich, ohne Parameter.';
        }
        if ($client === '' || strlen($client) > 255) {
            $err['client_id'] = 'Client-ID erforderlich.';
        }
        if (!in_array($role, ['reader', 'approver', 'contract_manager'], true)) {
            $err['default_role'] = 'Ungültige Rolle (Administrator-Rechte werden nie automatisch vergeben).';
        }
        if ($auto === 1 && $domains === '') {
            $err['allowed_domains'] = 'Für die automatische Kontoerstellung mindestens eine erlaubte E-Mail-Domain angeben.';
        }
        if ($err !== []) {
            throw new ValidationException($err);
        }
        if ($enabled === 1) { // Erreichbarkeit und Konfiguration jetzt prüfen, damit die Anmeldung nicht kaputt geht
            try {
                $this->oidc->discover($issuer);
            } catch (OidcException $e) {
                throw new ValidationException(['issuer' => 'Der Anbieter konnte nicht geprüft werden: ' . $e->getMessage()]);
            }
        }
        $existing = $this->db->one('SELECT client_secret_enc FROM tenant_sso WHERE tenant_id = ?', [$t]);
        $enc = $secret !== '' ? Crypto::encrypt($secret, $this->appKey) : ($existing['client_secret_enc'] ?? null);
        $this->db->run(
            'INSERT INTO tenant_sso (tenant_id, issuer, client_id, client_secret_enc, enabled, auto_provision, default_role, allowed_domains) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE issuer = VALUES(issuer), client_id = VALUES(client_id), client_secret_enc = VALUES(client_secret_enc), enabled = VALUES(enabled), auto_provision = VALUES(auto_provision), default_role = VALUES(default_role), allowed_domains = VALUES(allowed_domains)',
            [$t, $issuer, $client, $enc, $enabled, $auto, $role, $domains === '' ? null : $domains],
        );
        if ($existing !== null && ($in['issuer_changed_reset'] ?? '') === '1') {
            $this->db->run('DELETE FROM user_identities WHERE tenant_id = ? AND issuer <> ?', [$t, $issuer]);
        }
        $this->audit->log('sso.configured', 'success', $t, $p->userId, 'tenant', $t, ['enabled' => $enabled, 'auto_provision' => $auto, 'issuer' => $issuer, 'secret_changed' => $secret !== '']);
    }

    public function remove(Principal $p): void
    {
        $t = $this->tenant($p);
        $this->db->run('DELETE FROM user_identities WHERE tenant_id = ?', [$t]);
        $this->db->run('DELETE FROM tenant_sso WHERE tenant_id = ?', [$t]);
        $this->audit->log('sso.removed', 'success', $t, $p->userId, 'tenant', $t);
    }

    /** @return list<array<string,mixed>> */
    public function identities(Principal $p): array
    {
        $t = $this->tenant($p);
        return $this->db->all('SELECT i.id, i.email_at_link, i.created_at, i.last_login_at, u.display_name, u.email FROM user_identities i JOIN users u ON u.id = i.user_id WHERE i.tenant_id = ? ORDER BY i.created_at DESC LIMIT 200', [$t]);
    }

    public function unlink(Principal $p, int $identityId): void
    {
        $t = $this->tenant($p);
        if ($this->db->execute('DELETE FROM user_identities WHERE id = ? AND tenant_id = ?', [$identityId, $t]) < 1) {
            throw new NotFoundException();
        }
        $this->audit->log('sso.unlinked', 'success', $t, $p->userId, 'user_identity', $identityId);
    }

    public function redirectUri(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/anmelden/sso/rueckkehr';
    }

    // ------------------------------------------------------------------ Anmeldung

    /** Findet den Mandanten für Kürzel (Slug) oder E-Mail-Domain. Antwort nie unterscheidbar nach Grund. */
    public function tenantFor(string $hint): ?int
    {
        $hint = strtolower(trim($hint));
        if ($hint === '' || strlen($hint) > 190) {
            return null;
        }
        if (str_contains($hint, '@')) {
            $dom = substr($hint, strrpos($hint, '@') + 1);
            foreach ($this->db->all("SELECT s.tenant_id, s.allowed_domains FROM tenant_sso s JOIN tenants t ON t.id = s.tenant_id WHERE s.enabled = 1 AND t.status = 'active' AND s.allowed_domains IS NOT NULL") as $r) {
                if (in_array($dom, explode(',', (string) $r['allowed_domains']), true)) {
                    return (int) $r['tenant_id'];
                }
            }
            return null;
        }
        $id = $this->db->value("SELECT t.id FROM tenants t JOIN tenant_sso s ON s.tenant_id = t.id WHERE t.slug = ? AND s.enabled = 1 AND t.status = 'active'", [$hint]);
        return $id === null || $id === false ? null : (int) $id;
    }

    /**
     * @return array{url:string,state:array<string,mixed>} Weiterleitung und Zustand für die Sitzung
     * @throws OidcException
     */
    public function begin(int $tenantId, string $baseUrl, string $ip, string $next): array
    {
        $this->limiter->assertBelow(RateLimiter::bucket('sso_start', $ip), 30, 600);
        $this->limiter->hit(RateLimiter::bucket('sso_start', $ip));
        $c = $this->db->one('SELECT * FROM tenant_sso WHERE tenant_id = ? AND enabled = 1', [$tenantId]);
        if ($c === null) {
            throw new OidcException('SSO ist nicht eingerichtet.');
        }
        $disc = $this->oidc->discover((string) $c['issuer']);
        $state = Crypto::token(24);
        $nonce = Crypto::token(24);
        $verifier = Crypto::token(48);
        return [
            'url' => $this->oidc->authorizationUrl($disc['authorization_endpoint'], (string) $c['client_id'], $this->redirectUri($baseUrl), $state, $nonce, $verifier),
            'state' => ['s' => $state, 'n' => $nonce, 'v' => $verifier, 't' => $tenantId, 'exp' => $this->clock->now()->getTimestamp() + 600, 'next' => $next],
        ];
    }

    /**
     * Schliesst die Anmeldung ab.
     *
     * @param array<string,mixed> $saved Zustand aus der Sitzung
     * @return array{user_id:int,tenant_id:int}
     * @throws OidcException bei jedem Fehler (Meldung ist für die Anzeige geeignet)
     */
    public function finish(array $saved, string $state, string $code, string $baseUrl, string $ip): array
    {
        $now = $this->clock->now()->getTimestamp();
        if (!isset($saved['s'], $saved['n'], $saved['v'], $saved['t'], $saved['exp']) || $saved['exp'] < $now || !hash_equals((string) $saved['s'], $state) || $code === '' || strlen($code) > 2000) {
            throw new OidcException('Die Anmeldung ist abgelaufen oder ungültig. Bitte erneut starten.');
        }
        $tid = (int) $saved['t'];
        $this->limiter->assertBelow(RateLimiter::bucket('sso_fail', $ip), 10, 600);
        try {
            $c = $this->db->one("SELECT s.* FROM tenant_sso s JOIN tenants t ON t.id = s.tenant_id WHERE s.tenant_id = ? AND s.enabled = 1 AND t.status = 'active'", [$tid]);
            if ($c === null) {
                throw new OidcException('SSO ist nicht eingerichtet.');
            }
            $secret = $c['client_secret_enc'] !== null ? Crypto::decrypt((string) $c['client_secret_enc'], $this->appKey) : null;
            $disc = $this->oidc->discover((string) $c['issuer']);
            $claims = $this->oidc->exchange($disc, (string) $c['client_id'], $secret, $this->redirectUri($baseUrl), $code, (string) $saved['v'], (string) $saved['n'], $now);
            $uid = $this->resolveUser($c, $claims, $ip);
        } catch (OidcException $e) {
            $this->limiter->hit(RateLimiter::bucket('sso_fail', $ip));
            $this->audit->log('sso.login', 'failure', $tid, null, 'tenant', $tid, ['grund' => mb_substr($e->getMessage(), 0, 160)], null, $ip);
            throw $e;
        }
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->clock->now()->format('Y-m-d H:i:s.u'), $uid]);
        $this->audit->log('sso.login', 'success', $tid, $uid, 'user', $uid, [], null, $ip);
        return ['user_id' => $uid, 'tenant_id' => $tid];
    }

    /**
     * @param array<string,mixed> $c Konfiguration
     * @param array<string,mixed> $claims
     */
    private function resolveUser(array $c, array $claims, string $ip): int
    {
        $tid = (int) $c['tenant_id'];
        $issuer = (string) $c['issuer'];
        $sub = (string) $claims['sub'];
        $deny = 'Für diese Identität besteht kein Zugang zu diesem Mandanten. Bitte wenden Sie sich an Ihre Administration.';

        $row = $this->db->one(
            "SELECT i.id AS iid, u.id, u.status, u.is_system_admin, u.user_type FROM user_identities i JOIN users u ON u.id = i.user_id WHERE i.tenant_id = ? AND i.issuer = ? AND i.subject = ?",
            [$tid, $issuer, $sub],
        );
        if ($row !== null) {
            $this->assertUsable($row, $tid, $deny);
            $this->db->run('UPDATE user_identities SET last_login_at = ? WHERE id = ?', [$this->clock->now()->format('Y-m-d H:i:s.u'), $row['iid']]);
            return (int) $row['id'];
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ($claims['email_verified'] ?? false) === true;
        if ($email === '' || !$verified || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            throw new OidcException('Der Anbieter hat keine bestätigte E-Mail-Adresse geliefert.');
        }
        $domains = $c['allowed_domains'] !== null ? explode(',', (string) $c['allowed_domains']) : [];
        $domainOk = $domains === [] || in_array(substr($email, strrpos($email, '@') + 1), $domains, true);
        if (!$domainOk) {
            throw new OidcException($deny);
        }
        $u = $this->db->one('SELECT id, status, is_system_admin, user_type FROM users WHERE email = ?', [$email]);
        if ($u !== null) {
            $this->assertUsable($u, $tid, $deny); // muss bereits aktives Mitglied dieses Mandanten sein
            $this->db->run('INSERT INTO user_identities (tenant_id, user_id, issuer, subject, email_at_link, last_login_at) VALUES (?,?,?,?,?,?)', [$tid, $u['id'], $issuer, $sub, $email, $this->clock->now()->format('Y-m-d H:i:s.u')]);
            $this->audit->log('sso.linked', 'success', $tid, (int) $u['id'], 'user', (int) $u['id'], ['via' => 'verified_email'], null, $ip);
            return (int) $u['id'];
        }
        if ((int) $c['auto_provision'] !== 1 || $domains === []) {
            throw new OidcException($deny);
        }
        $name = trim((string) ($claims['name'] ?? '')) !== '' ? mb_substr(trim((string) $claims['name']), 0, 190) : $email;
        return $this->db->transaction(function () use ($tid, $email, $name, $issuer, $sub, $c, $ip): int {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->db->run("INSERT INTO users (email, display_name, user_type, status, email_verified_at) VALUES (?,?, 'internal', 'active', ?)", [$email, $name, $now]);
            $uid = $this->db->lastId();
            $this->db->run('INSERT INTO tenant_memberships (tenant_id, user_id, role_id) SELECT ?, ?, id FROM roles WHERE code = ?', [$tid, $uid, $c['default_role']]);
            $this->db->run('INSERT INTO user_identities (tenant_id, user_id, issuer, subject, email_at_link, last_login_at) VALUES (?,?,?,?,?,?)', [$tid, $uid, $issuer, $sub, $email, $now]);
            $this->audit->log('sso.provisioned', 'success', $tid, $uid, 'user', $uid, ['role' => $c['default_role']], null, $ip);
            return $uid;
        });
    }

    /** @param array<string,mixed> $u */
    private function assertUsable(array $u, int $tid, string $deny): void
    {
        if ($u['status'] !== 'active' || (int) $u['is_system_admin'] === 1 || $u['user_type'] !== 'internal') {
            throw new OidcException($deny);
        }
        if (!$this->db->value("SELECT 1 FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND r.is_external = 0", [$tid, $u['id']])) {
            throw new OidcException($deny);
        }
    }

    private function normalizeDomains(string $raw): string
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            $d = ltrim($d, '@');
            if (preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d) !== 1) {
                throw new ValidationException(['allowed_domains' => 'Ungültige Domain: ' . mb_substr($d, 0, 60)]);
            }
            $out[$d] = true;
        }
        return implode(',', array_keys($out));
    }

    private function tenant(Principal $p): int
    {
        $this->ac->require($p, 'tenant.manage');
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }
}
