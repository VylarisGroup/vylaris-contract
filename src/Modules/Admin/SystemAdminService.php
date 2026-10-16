<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Security\AuthService;

/**
 * Systemadministration: Mandanten anlegen/sperren. Systemadmins sehen KEINE Vertragsinhalte
 * (ihre Rolle enthält keine Vertragsrechte) – sie verwalten nur Mandanten und Technik.
 */
final class SystemAdminService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit, private readonly AuthService $auth)
    {
    }

    private function require(Principal $p): void
    {
        if (!$p->isSystemAdmin || $p->isExternal() || !$p->can('system.manage')) {
            $this->audit->log('access.denied', 'denied', $p->tenantId, $p->userId, 'system', null, ['reason' => 'not_system_admin']);
            throw new ForbiddenException();
        }
    }

    public function createTenant(Principal $p, string $name, string $slug, string $adminEmail, string $adminName): int
    {
        $this->require($p);
        $slug = strtolower(trim($slug));
        if (trim($name) === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['name' => 'Name erforderlich.']);
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $slug) !== 1) {
            throw new ValidationException(['slug' => 'Kürzel: 2–61 Zeichen (a–z, 0–9, -).']);
        }
        $adminEmail = strtolower(trim($adminEmail));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false || trim($adminName) === '') {
            throw new ValidationException(['admin_email' => 'Name und gültige E-Mail der ersten Administration erforderlich.']);
        }
        if ($this->db->value('SELECT 1 FROM tenants WHERE slug = ?', [$slug])) {
            throw new ValidationException(['slug' => 'Dieses Kürzel ist vergeben.']);
        }
        $newUser = false;
        $out = $this->db->transaction(function () use ($p, $name, $slug, $adminEmail, $adminName, &$newUser): array {
            $this->db->run('INSERT INTO tenants (name, slug) VALUES (?,?)', [trim($name), $slug]);
            $tid = $this->db->lastId();
            $u = $this->db->one('SELECT id, user_type FROM users WHERE email = ?', [$adminEmail]);
            if ($u !== null && $u['user_type'] !== 'internal') {
                throw new BusinessRuleException('Die E-Mail-Adresse gehört zu einem externen Konto.');
            }
            if ($u === null) {
                $this->db->run("INSERT INTO users (email, display_name, user_type, status) VALUES (?,?, 'internal', 'pending')", [$adminEmail, trim($adminName)]);
                $uid = $this->db->lastId();
                $newUser = true;
            } else {
                $uid = (int) $u['id'];
            }
            $this->db->run("INSERT INTO tenant_memberships (tenant_id, user_id, role_id, created_by) SELECT ?, ?, id, ? FROM roles WHERE code = 'tenant_admin'", [$tid, $uid, $p->userId]);
            $this->audit->log('tenant.created', 'success', $tid, $p->userId, 'tenant', $tid, ['slug' => $slug]);
            return ['tid' => $tid, 'uid' => $uid];
        });
        if ($newUser) {
            $this->auth->sendInvitation($out['uid'], $p->displayName, 'Mandant ' . trim($name));
        }
        return $out['tid'];
    }

    public function setTenantStatus(Principal $p, int $tenantId, string $status): void
    {
        $this->require($p);
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new ValidationException(['status' => 'Ungültiger Status.']);
        }
        if ($this->db->execute('UPDATE tenants SET status = ? WHERE id = ?', [$status, $tenantId]) === 0 && !$this->db->value('SELECT 1 FROM tenants WHERE id = ?', [$tenantId])) {
            throw new NotFoundException();
        }
        $this->audit->log('tenant.status_' . $status, 'success', $tenantId, $p->userId, 'tenant', $tenantId);
    }

    /** @return list<array<string,mixed>> */
    public function tenants(Principal $p): array
    {
        $this->require($p);
        return $this->db->all('SELECT t.id, t.name, t.slug, t.status, t.created_at, (SELECT COUNT(*) FROM tenant_memberships tm WHERE tm.tenant_id = t.id AND tm.status = \'active\') AS members FROM tenants t ORDER BY t.name');
    }
}
