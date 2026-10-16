<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Lesezugriffe für Auswahllisten und Nebenlisten eines Vertrags. Der Aufrufer hat die Sichtbarkeit des Vertrags
 * zuvor über AccessControl/ContractService geprüft; jede Abfrage ist zusätzlich auf den Mandanten begrenzt.
 */
final class ContractReadRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,string> */
    public function contractTypes(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, name FROM contract_types WHERE tenant_id IS NULL OR tenant_id = ? ORDER BY name', [$tenantId]) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** @return array<int,string> */
    public function departments(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, name FROM departments WHERE tenant_id = ? ORDER BY name', [$tenantId]) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** @return array<int,string> aktive interne Mitglieder (Name <E-Mail>) */
    public function members(int $tenantId, ?string $permission = null): array
    {
        $out = [];
        $join = $permission === null ? '' : ' JOIN role_permissions rp ON rp.role_id = tm.role_id JOIN permissions pm ON pm.id = rp.permission_id AND pm.code = ?';
        foreach ($this->db->all(
            "SELECT DISTINCT u.id, u.display_name, u.email FROM users u JOIN tenant_memberships tm ON tm.user_id = u.id{$join}
              WHERE tm.tenant_id = ? AND tm.status = 'active' AND u.status = 'active' AND u.user_type = 'internal' ORDER BY u.display_name",
            $permission === null ? [$tenantId] : [$permission, $tenantId],
        ) as $r) {
            $out[(int) $r['id']] = $r['display_name'] . ' <' . $r['email'] . '>';
        }
        return $out;
    }

    /** @return array<int,string> Notariats-Benutzer mit Mitgliedschaft im Mandanten */
    public function notaries(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT DISTINCT u.id, u.display_name, u.email FROM users u JOIN tenant_memberships tm ON tm.user_id = u.id
               JOIN roles r ON r.id = tm.role_id
              WHERE tm.tenant_id = ? AND tm.status = 'active' AND u.status = 'active' AND u.user_type = 'notary' AND r.code = 'notary' ORDER BY u.display_name",
            [$tenantId],
        ) as $r) {
            $out[(int) $r['id']] = $r['display_name'] . ' <' . $r['email'] . '>';
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function customFields(int $tenantId): array
    {
        return $this->db->all('SELECT id, code, label, field_type, options, is_required FROM custom_field_definitions WHERE tenant_id = ? ORDER BY label', [$tenantId]);
    }

    /** @return list<array<string,mixed>> */
    public function approvalsFor(int $contractId, int $tenantId): array
    {
        return $this->db->all(
            'SELECT a.id, a.contract_version_id, a.step_no, a.status, a.decision_comment, a.decided_at, a.approver_user_id, u.display_name AS approver_name, v.version_no
               FROM approvals a JOIN users u ON u.id = a.approver_user_id JOIN contract_versions v ON v.id = a.contract_version_id
              WHERE a.contract_id = ? AND a.tenant_id = ? ORDER BY a.contract_version_id DESC, a.step_no, a.id',
            [$contractId, $tenantId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function signaturesFor(int $contractId, int $tenantId): array
    {
        $rows = $this->db->all(
            'SELECT id, contract_version_id, provider, mode, requested_level, status, signers, last_error, created_at, updated_at
               FROM signature_requests WHERE contract_id = ? AND tenant_id = ? ORDER BY id DESC',
            [$contractId, $tenantId],
        );
        foreach ($rows as &$r) {
            $j = json_decode((string) $r['signers'], true);
            $r['signers'] = is_array($j) ? $j : [];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function notaryCasesFor(int $contractId, int $tenantId): array
    {
        return $this->db->all(
            'SELECT n.id, n.status, n.notary_office, n.provided_at, n.returned_at, n.remarks, u.display_name AS notary_name
               FROM notary_cases n JOIN users u ON u.id = n.notary_user_id WHERE n.contract_id = ? AND n.tenant_id = ? ORDER BY n.id DESC',
            [$contractId, $tenantId],
        );
    }

    /** @param list<string> $numbers @return list<int> */
    public function contractIdsByNumbers(int $tenantId, array $numbers): array
    {
        $ids = [];
        foreach (array_slice($numbers, 0, 100) as $n) {
            $id = $this->db->value('SELECT id FROM contracts WHERE tenant_id = ? AND contract_number = ?', [$tenantId, trim($n)]);
            if ($id !== null && $id !== false) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> Prüfprotokoll des Mandanten (neueste zuerst) */
    public function auditEvents(int $tenantId, int $limit, int $offset, string $action = ''): array
    {
        $where = 'e.tenant_id = ?';
        $params = [$tenantId];
        if ($action !== '') {
            $where .= ' AND e.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%';
        }
        return $this->db->all(
            "SELECT e.seq, e.occurred_at, e.action, e.outcome, e.object_type, e.object_id, u.display_name AS actor
               FROM audit_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE {$where} ORDER BY e.seq DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $params,
        );
    }

    /** @return list<array<string,mixed>> */
    public function tenantContractsBrief(int $tenantId): array
    {
        return $this->db->all('SELECT id, contract_number, title FROM contracts WHERE tenant_id = ? ORDER BY id DESC LIMIT 300', [$tenantId]);
    }

    /** Rohtext der aktuellen Version (nur nach erfolgreicher Bearbeitungsprüfung des Aufrufers verwenden). */
    public function currentBodyHtml(int $contractId, int $tenantId): array
    {
        return $this->db->one(
            'SELECT v.id, v.version_no, v.state, v.body_html FROM contracts c JOIN contract_versions v ON v.id = c.current_version_id
              WHERE c.id = ? AND c.tenant_id = ?',
            [$contractId, $tenantId],
        ) ?? ['id' => 0, 'version_no' => 0, 'state' => 'draft', 'body_html' => ''];
    }

    /** @return array<string,string> code => Wert der benutzerdefinierten Felder */
    public function customValues(int $contractId, int $tenantId): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT d.code, v.value_text FROM custom_field_values v JOIN custom_field_definitions d ON d.id = v.field_id
              JOIN contracts c ON c.id = v.contract_id WHERE v.contract_id = ? AND c.tenant_id = ? AND d.tenant_id = ?',
            [$contractId, $tenantId, $tenantId],
        ) as $r) {
            $out[(string) $r['code']] = (string) $r['value_text'];
        }
        return $out;
    }
}
