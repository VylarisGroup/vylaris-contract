<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Security\AuthService;
use App\Services\AccessControl;

/** Mandantenverwaltung: Benutzer, Rollen, Abteilungen, Einstellungen, Vertragstypen, Zusatzfelder. */
final class TenantAdminService
{
    public const INTERNAL_ROLES = ['tenant_admin', 'contract_manager', 'reader', 'approver'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly AuthService $auth,
    ) {
    }

    // ------------------------------------------------------------------ Benutzer

    /** @return list<array<string,mixed>> */
    public function members(Principal $p): array
    {
        $t = $this->tenant($p, 'user.manage');
        return $this->db->all(
            "SELECT u.id, u.email, u.display_name, u.status AS user_status, u.mfa_enabled_at, (SELECT COUNT(*) FROM passkeys pk WHERE pk.user_id = u.id) AS passkey_count, u.last_login_at, tm.status AS membership_status, GROUP_CONCAT(r.code ORDER BY r.code) AS roles
               FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id JOIN roles r ON r.id = tm.role_id
              WHERE tm.tenant_id = ? AND r.is_external = 0 GROUP BY u.id, u.email, u.display_name, u.status, u.mfa_enabled_at, u.last_login_at, tm.status ORDER BY u.display_name",
            [$t],
        );
    }

    /** @param list<int> $departmentIds */
    public function invite(Principal $p, string $email, string $name, string $roleCode, array $departmentIds = []): int
    {
        $t = $this->tenant($p, 'user.manage');
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            throw new ValidationException(['email' => 'Gültige E-Mail-Adresse erforderlich.']);
        }
        if (trim($name) === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['display_name' => 'Name erforderlich.']);
        }
        $this->assertInternalRole($roleCode);
        foreach ($departmentIds as $d) {
            if (!$this->db->value('SELECT 1 FROM departments WHERE id = ? AND tenant_id = ?', [(int) $d, $t])) {
                throw new ValidationException(['departments' => 'Ungültige Abteilung.']);
            }
        }
        $newUser = false;
        $uid = $this->db->transaction(function () use ($p, $t, $email, $name, $roleCode, $departmentIds, &$newUser): int {
            $u = $this->db->one('SELECT id, user_type, is_system_admin FROM users WHERE email = ?', [$email]);
            if ($u !== null && $u['user_type'] !== 'internal') {
                throw new BusinessRuleException('Diese E-Mail-Adresse gehört zu einem externen Konto und kann nicht als interner Benutzer eingeladen werden.');
            }
            if ($u === null) {
                $this->db->run("INSERT INTO users (email, display_name, user_type, status) VALUES (?,?, 'internal', 'pending')", [$email, trim($name)]);
                $uid = $this->db->lastId();
                $newUser = true;
            } else {
                $uid = (int) $u['id'];
            }
            $this->db->run('INSERT IGNORE INTO tenant_memberships (tenant_id, user_id, role_id, created_by) SELECT ?, ?, id, ? FROM roles WHERE code = ?', [$t, $uid, $p->userId, $roleCode]);
            foreach ($departmentIds as $d) {
                $this->db->run('INSERT IGNORE INTO department_memberships (tenant_id, department_id, user_id) VALUES (?,?,?)', [$t, (int) $d, $uid]);
            }
            $this->audit->log('user.invited', 'success', $t, $p->userId, 'user', $uid, ['role' => $roleCode, 'new_account' => $newUser]);
            return $uid;
        });
        if ($newUser) {
            $tn = (string) $this->db->value('SELECT name FROM tenants WHERE id = ?', [$t]);
            $this->auth->sendInvitation($uid, $p->displayName !== '' ? $p->displayName : 'Ihre Administration', 'Mandant ' . $tn);
        }
        return $uid;
    }

    public function resendInvitation(Principal $p, int $userId): void
    {
        $t = $this->tenant($p, 'user.manage');
        $u = $this->db->one("SELECT u.id FROM users u JOIN tenant_memberships tm ON tm.user_id = u.id WHERE u.id = ? AND tm.tenant_id = ? AND u.status = 'pending' LIMIT 1", [$userId, $t]);
        if ($u === null) {
            throw new NotFoundException();
        }
        $this->auth->sendInvitation($userId, $p->displayName, 'Mandant');
        $this->audit->log('user.invitation_resent', 'success', $t, $p->userId, 'user', $userId);
    }

    public function setRole(Principal $p, int $userId, string $roleCode): void
    {
        $t = $this->tenant($p, 'user.manage');
        $this->assertInternalRole($roleCode);
        $this->assertMember($t, $userId);
        $this->db->transaction(function () use ($p, $t, $userId, $roleCode): void {
            $this->db->run('DELETE tm FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND r.is_external = 0', [$t, $userId]);
            $this->db->run('INSERT INTO tenant_memberships (tenant_id, user_id, role_id, created_by) SELECT ?, ?, id, ? FROM roles WHERE code = ?', [$t, $userId, $p->userId, $roleCode]);
            $this->assertAdminRemains($t);
            $this->db->run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
            $this->audit->log('user.role_changed', 'success', $t, $p->userId, 'user', $userId, ['role' => $roleCode]);
        });
    }

    public function setMembershipStatus(Principal $p, int $userId, string $status): void
    {
        $t = $this->tenant($p, 'user.manage');
        if (!in_array($status, ['active', 'suspended', 'revoked'], true)) {
            throw new ValidationException(['status' => 'Ungültiger Status.']);
        }
        $this->assertMember($t, $userId);
        $this->db->transaction(function () use ($p, $t, $userId, $status): void {
            $this->db->run('UPDATE tenant_memberships tm JOIN roles r ON r.id = tm.role_id SET tm.status = ? WHERE tm.tenant_id = ? AND tm.user_id = ? AND r.is_external = 0', [$status, $t, $userId]);
            $this->assertAdminRemains($t);
            $this->audit->log('user.membership_' . $status, 'success', $t, $p->userId, 'user', $userId);
        });
    }

    /** MFA-Reset (Gerät verloren): nur Mandantenadmin, nur für Mitglieder, protokolliert. */
    public function resetMfa(Principal $p, int $userId): void
    {
        $t = $this->tenant($p, 'user.manage');
        $this->assertMember($t, $userId);
        $this->auth->adminResetMfa($userId, $p->userId, $t);
    }

    // ------------------------------------------------------------------ Abteilungen

    public function saveDepartment(Principal $p, string $name, ?int $id = null): int
    {
        $t = $this->tenant($p, 'department.manage');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['name' => 'Name erforderlich.']);
        }
        $dup = $this->db->value('SELECT id FROM departments WHERE tenant_id = ? AND name = ? AND id <> ?', [$t, $name, $id ?? 0]);
        if ($dup) {
            throw new ValidationException(['name' => 'Eine Abteilung mit diesem Namen existiert bereits.']);
        }
        if ($id === null) {
            $this->db->run('INSERT INTO departments (tenant_id, name) VALUES (?,?)', [$t, $name]);
            $id = $this->db->lastId();
        } else {
            if ($this->db->execute('UPDATE departments SET name = ? WHERE id = ? AND tenant_id = ?', [$name, $id, $t]) === 0 && !$this->db->value('SELECT 1 FROM departments WHERE id = ? AND tenant_id = ?', [$id, $t])) {
                throw new NotFoundException();
            }
        }
        $this->audit->log('department.saved', 'success', $t, $p->userId, 'department', $id);
        return $id;
    }

    public function setDepartmentMember(Principal $p, int $departmentId, int $userId, bool $member): void
    {
        $t = $this->tenant($p, 'department.manage');
        if (!$this->db->value('SELECT 1 FROM departments WHERE id = ? AND tenant_id = ?', [$departmentId, $t])) {
            throw new NotFoundException();
        }
        $this->assertMember($t, $userId);
        if ($member) {
            $this->db->run('INSERT IGNORE INTO department_memberships (tenant_id, department_id, user_id) VALUES (?,?,?)', [$t, $departmentId, $userId]);
        } else {
            $this->db->run('DELETE FROM department_memberships WHERE tenant_id = ? AND department_id = ? AND user_id = ?', [$t, $departmentId, $userId]);
        }
        $this->audit->log('department.member_' . ($member ? 'added' : 'removed'), 'success', $t, $p->userId, 'department', $departmentId, ['user_id' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function departments(Principal $p): array
    {
        $t = $this->tenant($p, 'department.manage');
        return $this->db->all('SELECT d.id, d.name, (SELECT COUNT(*) FROM department_memberships dm WHERE dm.department_id = d.id) AS members, (SELECT COUNT(*) FROM contracts c WHERE c.department_id = d.id) AS contracts FROM departments d WHERE d.tenant_id = ? ORDER BY d.name', [$t]);
    }

    // ------------------------------------------------------------------ Einstellungen

    /** @param array<string,mixed> $in */
    public function saveSettings(Principal $p, array $in): void
    {
        $t = $this->tenant($p, 'tenant.manage');
        $name = trim((string) ($in['name'] ?? ''));
        $tz = (string) ($in['timezone'] ?? 'Europe/Zurich');
        $jur = strtoupper(trim((string) ($in['jurisdiction_default'] ?? 'CH')));
        $prefix = trim((string) ($in['contract_number_prefix'] ?? 'V'));
        $err = [];
        if ($name === '' || mb_strlen($name) > 190) {
            $err['name'] = 'Name erforderlich.';
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $err['timezone'] = 'Ungültige Zeitzone.';
        }
        if (preg_match('/^[A-Z]{2}(-[A-Z]{2,3})?$|^EU$/', $jur) !== 1) {
            $err['jurisdiction_default'] = 'Rechtsgebiet (z. B. CH, DE, EU).';
        }
        if (preg_match('/^[A-Za-z0-9]{1,8}$/', $prefix) !== 1) {
            $err['contract_number_prefix'] = 'Präfix: 1–8 Buchstaben/Ziffern.';
        }
        if ($err !== []) {
            throw new ValidationException($err);
        }
        $mfa = !empty($in['mfa_required']) ? 1 : 0;
        $this->db->run('UPDATE tenants SET name=?, timezone=?, jurisdiction_default=?, contract_number_prefix=?, mfa_required=? WHERE id=?', [$name, $tz, $jur, $prefix, $mfa, $t]);
        $this->db->run('UPDATE counterparties SET name = ? WHERE linked_tenant_id = ?', [mb_substr($name, 0, 255), $t]); // Mandantenname bei verbundenen Gegenparteien nachführen
        $this->audit->log('tenant.settings_saved', 'success', $t, $p->userId, 'tenant', $t, ['mfa_required' => $mfa]);
    }

    /** @return array<string,mixed> */
    public function settings(Principal $p): array
    {
        $t = $this->tenant($p, 'tenant.manage');
        return (array) $this->db->one('SELECT id, name, slug, timezone, jurisdiction_default, contract_number_prefix, mfa_required FROM tenants WHERE id = ?', [$t]);
    }

    public function saveContractType(Principal $p, string $code, string $name): int
    {
        $t = $this->tenant($p, 'tenant.manage');
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $code) !== 1 || trim($name) === '' || mb_strlen($name) > 120) {
            throw new ValidationException(['code' => 'Code (a–z, 0–9, _) und Name erforderlich.']);
        }
        if ($this->db->value('SELECT 1 FROM contract_types WHERE code = ? AND (tenant_id IS NULL OR tenant_id = ?)', [$code, $t])) {
            throw new ValidationException(['code' => 'Dieser Code existiert bereits.']);
        }
        $this->db->run('INSERT INTO contract_types (tenant_id, code, name) VALUES (?,?,?)', [$t, $code, trim($name)]);
        return $this->db->lastId();
    }

    public function saveCustomField(Principal $p, string $code, string $label, string $type, bool $required, array $options = []): int
    {
        $t = $this->tenant($p, 'tenant.manage');
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $code) !== 1 || trim($label) === '' || mb_strlen($label) > 120 || !in_array($type, ['text', 'number', 'date', 'boolean', 'select'], true)) {
            throw new ValidationException(['code' => 'Code, Beschriftung und gültiger Typ erforderlich.']);
        }
        if ($type === 'select' && $options === []) {
            throw new ValidationException(['options' => 'Auswahlfelder brauchen Optionen.']);
        }
        if ($this->db->value('SELECT 1 FROM custom_field_definitions WHERE tenant_id = ? AND code = ?', [$t, $code])) {
            throw new ValidationException(['code' => 'Dieser Code existiert bereits.']);
        }
        $this->db->run('INSERT INTO custom_field_definitions (tenant_id, code, label, field_type, options, is_required) VALUES (?,?,?,?,?,?)', [$t, $code, trim($label), $type, $type === 'select' ? json_encode(array_values($options), JSON_UNESCAPED_UNICODE) : null, $required ? 1 : 0]);
        return $this->db->lastId();
    }

    // ------------------------------------------------------------------ intern

    private function tenant(Principal $p, string $perm): int
    {
        $this->ac->require($p, $perm);
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }

    private function assertInternalRole(string $role): void
    {
        if (!in_array($role, self::INTERNAL_ROLES, true)) {
            throw new ValidationException(['role' => 'Ungültige Rolle.']);
        }
    }

    private function assertMember(int $t, int $userId): void
    {
        if (!$this->db->value('SELECT 1 FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND r.is_external = 0', [$t, $userId])) {
            throw new NotFoundException();
        }
    }

    /** Verhindert, dass ein Mandant ohne aktive Administration zurückbleibt. */
    private function assertAdminRemains(int $t): void
    {
        $n = (int) $this->db->value("SELECT COUNT(*) FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.tenant_id = ? AND r.code = 'tenant_admin' AND tm.status = 'active'", [$t]);
        if ($n < 1) {
            throw new BusinessRuleException('Der Mandant braucht mindestens eine aktive Administration.');
        }
    }
}
