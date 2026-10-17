<?php

declare(strict_types=1);

namespace App\Modules\Connections;

use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Notifications\NotificationService;
use App\Security\RateLimiter;
use App\Services\AccessControl;

/**
 * Mandanten-Code und Verbindungen. Gegenparteien entstehen nur noch, indem sich zwei Mandanten verbinden:
 * Mandant A gibt den Code von Mandant B ein, B bestätigt. Beide Seiten erhalten danach die jeweils andere
 * als Gegenpartei (Name = Mandantenname, von der Gegenseite selbst gepflegt). Details der Verbindung (Teilen von Verträgen)
 * regelt SharedContractService. Der Code ist ein Zugang zur Anfrage, kein Zugriff: ohne Bestätigung geschieht nichts.
 */
final class ConnectionService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TRIES_PER_HOUR = 10;

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
    ) {
    }

    public static function normalize(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        return strlen($c) === 12 ? implode('-', str_split($c, 4)) : '';
    }

    private function generate(): string
    {
        $s = '';
        for ($i = 0; $i < 12; $i++) {
            $s .= self::ALPHABET[random_int(0, 31)];
        }
        return implode('-', str_split($s, 4));
    }

    /** Eigener Code (wird beim ersten Aufruf erzeugt). */
    public function myCode(Principal $p): string
    {
        $tid = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        $code = $this->db->value('SELECT connect_code FROM tenants WHERE id = ?', [$tid]);
        if (is_string($code) && $code !== '') {
            return $code;
        }
        return $this->regenerate($p, false);
    }

    /** Neuer Code. Bestehende Verbindungen bleiben; nur künftige Anfragen brauchen den neuen Code. */
    public function regenerate(Principal $p, bool $audited = true): string
    {
        $tid = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        for ($i = 0; $i < 5; $i++) {
            $code = $this->generate();
            try {
                $this->db->run('UPDATE tenants SET connect_code = ? WHERE id = ?', [$code, $tid]);
                if ($audited) {
                    $this->audit->log('connection.code_regenerated', 'success', $tid, $p->userId, 'tenant', $tid);
                }
                return $code;
            } catch (\PDOException) {
                // Kollision mit anderem Mandanten: nächster Versuch
            }
        }
        throw new BusinessRuleException('Der Code konnte nicht erzeugt werden. Bitte erneut versuchen.');
    }

    /** Verbindungsanfrage an den Mandanten mit diesem Code. */
    public function request(Principal $p, string $code, string $message = ''): int
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        $this->limiter->assertBelow(RateLimiter::bucket('conncode', (string) $me), self::TRIES_PER_HOUR, 3600);
        $this->limiter->hit(RateLimiter::bucket('conncode', (string) $me));
        $norm = self::normalize($code);
        $generic = new BusinessRuleException('Mit diesem Code ist keine Verbindung möglich. Bitte Code prüfen oder neu erfragen.');
        if ($norm === '') {
            throw $generic;
        }
        $t = $this->db->one("SELECT id, name FROM tenants WHERE connect_code = ? AND status = 'active'", [$norm]);
        if ($t === null || (int) $t['id'] === $me) {
            $this->audit->log('connection.request_failed', 'denied', $me, $p->userId, 'tenant', $me);
            throw $generic;
        }
        $other = (int) $t['id'];
        $msg = mb_substr(trim($message), 0, 255);
        $existing = $this->db->one(
            'SELECT * FROM tenant_connections WHERE (requester_tenant_id = ? AND target_tenant_id = ?) OR (requester_tenant_id = ? AND target_tenant_id = ?)',
            [$me, $other, $other, $me],
        );
        if ($existing !== null && in_array($existing['status'], ['pending', 'active'], true)) {
            throw new BusinessRuleException($existing['status'] === 'active' ? 'Sie sind mit diesem Mandanten bereits verbunden.' : 'Es gibt bereits eine offene Anfrage zwischen diesen Mandanten.');
        }
        if ($existing !== null) {
            $this->db->run("UPDATE tenant_connections SET requester_tenant_id = ?, target_tenant_id = ?, status = 'pending', requested_by = ?, decided_by = NULL, message = ?, created_at = UTC_TIMESTAMP(6), decided_at = NULL, ended_at = NULL WHERE id = ?", [$me, $other, $p->userId, $msg !== '' ? $msg : null, (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $this->db->run('INSERT INTO tenant_connections (requester_tenant_id, target_tenant_id, requested_by, message) VALUES (?,?,?,?)', [$me, $other, $p->userId, $msg !== '' ? $msg : null]);
            $id = $this->db->lastId();
        }
        $this->audit->log('connection.requested', 'success', $me, $p->userId, 'tenant_connection', $id, ['target_tenant' => $other]);
        $myName = (string) $this->db->value('SELECT name FROM tenants WHERE id = ?', [$me]);
        foreach ($this->db->all("SELECT m.user_id FROM tenant_memberships m JOIN roles r ON r.id = m.role_id JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND r.code = 'tenant_admin' AND u.status = 'active'", [$other]) as $a) {
            $this->notifications->notify($other, (int) $a['user_id'], 'conn-req-' . $id . '-' . substr($this->clock->now()->format('YmdHis'), 0, 12), 'Verbindungsanfrage', $myName . ' möchte sich mit Ihnen verbinden. Bitte unter «Gegenparteien» prüfen.', '/gegenparteien');
        }
        return $id;
    }

    public function decide(Principal $p, int $id, bool $accept): void
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        $c = $this->db->one("SELECT * FROM tenant_connections WHERE id = ? AND target_tenant_id = ? AND status = 'pending'", [$id, $me]);
        if ($c === null) {
            throw new NotFoundException();
        }
        $this->db->transaction(function () use ($c, $accept, $p, $id): void {
            $this->db->run('UPDATE tenant_connections SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ?', [$accept ? 'active' : 'rejected', $p->userId, $id]);
            if ($accept) {
                $a = (int) $c['requester_tenant_id'];
                $b = (int) $c['target_tenant_id'];
                $this->link($a, $b);
                $this->link($b, $a);
            }
        });
        $this->audit->log($accept ? 'connection.accepted' : 'connection.rejected', 'success', $me, $p->userId, 'tenant_connection', $id);
    }

    /** Verbindung beenden (beide Seiten dürfen). Geteilte Verträge sind sofort nicht mehr sichtbar. */
    public function revoke(Principal $p, int $id): void
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        $n = $this->db->execute(
            "UPDATE tenant_connections SET status = 'revoked', ended_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'active' AND (requester_tenant_id = ? OR target_tenant_id = ?)",
            [$id, $me, $me],
        );
        if ($n === 0) {
            throw new NotFoundException();
        }
        $this->audit->log('connection.revoked', 'success', $me, $p->userId, 'tenant_connection', $id);
    }

    /** Anfrage zurückziehen (nur Absender, solange offen). */
    public function withdraw(Principal $p, int $id): void
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'tenant.manage');
        if ($this->db->execute("DELETE FROM tenant_connections WHERE id = ? AND requester_tenant_id = ? AND status = 'pending'", [$id, $me]) === 0) {
            throw new NotFoundException();
        }
        $this->audit->log('connection.withdrawn', 'success', $me, $p->userId, 'tenant_connection', $id);
    }

    /** @return array{incoming:list<array<string,mixed>>,outgoing:list<array<string,mixed>>,active:list<array<string,mixed>>,closed:list<array<string,mixed>>} */
    public function overview(Principal $p): array
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'contract.view');
        $rows = $this->db->all(
            'SELECT c.id, c.status, c.message, c.created_at, c.decided_at, c.requester_tenant_id, c.target_tenant_id,
                    IF(c.requester_tenant_id = ?, t2.name, t1.name) AS other_name, IF(c.requester_tenant_id = ?, 1, 0) AS i_requested
               FROM tenant_connections c JOIN tenants t1 ON t1.id = c.requester_tenant_id JOIN tenants t2 ON t2.id = c.target_tenant_id
              WHERE c.requester_tenant_id = ? OR c.target_tenant_id = ? ORDER BY c.created_at DESC',
            [$me, $me, $me, $me],
        );
        $out = ['incoming' => [], 'outgoing' => [], 'active' => [], 'closed' => []];
        foreach ($rows as $r) {
            if ($r['status'] === 'pending') {
                $out[(int) $r['i_requested'] === 1 ? 'outgoing' : 'incoming'][] = $r;
            } elseif ($r['status'] === 'active') {
                $out['active'][] = $r;
            } else {
                $out['closed'][] = $r;
            }
        }
        return $out;
    }

    /** Ist die Gegenpartei eine aktive Verbindung des Mandanten? (Voraussetzung für neue Vertragsparteien.) */
    public function isActiveLink(int $tenantId, int $counterpartyId): bool
    {
        return $this->db->value(
            "SELECT 1 FROM counterparties cp JOIN tenant_connections c ON c.status = 'active'
               AND ((c.requester_tenant_id = cp.tenant_id AND c.target_tenant_id = cp.linked_tenant_id) OR (c.target_tenant_id = cp.tenant_id AND c.requester_tenant_id = cp.linked_tenant_id))
              WHERE cp.id = ? AND cp.tenant_id = ? AND cp.archived_at IS NULL AND cp.linked_tenant_id IS NOT NULL",
            [$counterpartyId, $tenantId],
        ) !== null;
    }

    /** Namensübernahme nach Umbenennung des Mandanten. */
    public function syncName(int $tenantId): void
    {
        $name = (string) $this->db->value('SELECT name FROM tenants WHERE id = ?', [$tenantId]);
        if ($name !== '') {
            $this->db->run('UPDATE counterparties SET name = ? WHERE linked_tenant_id = ?', [mb_substr($name, 0, 255), $tenantId]);
        }
    }

    private function link(int $owner, int $other): void
    {
        $name = mb_substr((string) $this->db->value('SELECT name FROM tenants WHERE id = ?', [$other]), 0, 255);
        $row = $this->db->one('SELECT id FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$owner, $other]);
        if ($row !== null) {
            $this->db->run('UPDATE counterparties SET archived_at = NULL, name = ? WHERE id = ?', [$name, (int) $row['id']]);
            return;
        }
        $this->db->run("INSERT INTO counterparties (tenant_id, party_type, name, linked_tenant_id) VALUES (?, 'company', ?, ?)", [$owner, $name, $other]);
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }
}
