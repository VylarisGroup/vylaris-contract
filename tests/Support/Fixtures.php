<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;

/** Erzeugt ausschliesslich synthetische Testdaten direkt per SQL (umgeht bewusst die Services). */
final class Fixtures
{
    private int $seq = 0;

    public function __construct(private readonly Database $db)
    {
    }

    public function tenant(string $name = 'Test AG'): int
    {
        $this->seq++;
        $this->db->run('INSERT INTO tenants (name, slug) VALUES (?, ?)', [$name, 't' . $this->seq . bin2hex(random_bytes(3))]);
        return $this->db->lastId();
    }

    public function user(string $type = 'internal', string $status = 'active'): int
    {
        $this->seq++;
        $this->db->run(
            'INSERT INTO users (email, display_name, user_type, status) VALUES (?, ?, ?, ?)',
            ['u' . $this->seq . '.' . bin2hex(random_bytes(3)) . '@test.invalid', 'Testuser ' . $this->seq, $type, $status],
        );
        return $this->db->lastId();
    }

    public function member(int $tenantId, int $userId, string $roleCode): void
    {
        $this->db->run(
            "INSERT INTO tenant_memberships (tenant_id, user_id, role_id) SELECT ?, ?, id FROM roles WHERE code = ?",
            [$tenantId, $userId, $roleCode],
        );
    }

    /** Gegenpartei direkt per SQL (der Service legt keine von Hand mehr an). $linkedTenant = verbundener Mandant. */
    public function counterparty(int $tenantId, string $name = 'Muster Partner', array $extra = [], ?int $linkedTenant = null): int
    {
        $this->db->run(
            'INSERT INTO counterparties (tenant_id, party_type, name, contact_email, address_line, city, country, sensitive_data, linked_tenant_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$tenantId, $extra['party_type'] ?? 'company', $name, $extra['contact_email'] ?? null, $extra['address_line'] ?? null, $extra['city'] ?? null, $extra['country'] ?? null, (int) ($extra['sensitive_data'] ?? 0), $linkedTenant],
        );
        return $this->db->lastId();
    }

    public function department(int $tenantId, string $name = 'Einkauf'): int
    {
        $this->db->run('INSERT INTO departments (tenant_id, name) VALUES (?, ?)', [$tenantId, $name . $this->seq++]);
        return $this->db->lastId();
    }

    public function departmentMember(int $tenantId, int $departmentId, int $userId): void
    {
        $this->db->run('INSERT INTO department_memberships (tenant_id, department_id, user_id) VALUES (?,?,?)', [$tenantId, $departmentId, $userId]);
    }

    /** Legt einen Entwurfsvertrag mit Version 1 (Entwurf) an. */
    public function contract(int $tenantId, int $ownerId, ?int $departmentId = null, string $status = 'draft'): int
    {
        $this->seq++;
        $typeId = (int) $this->db->value("SELECT id FROM contract_types WHERE code = 'nda' AND tenant_id IS NULL");
        $this->db->run(
            'INSERT INTO contracts (tenant_id, department_id, contract_number, title, contract_type_id, owner_user_id, status, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$tenantId, $departmentId, 'T-' . $this->seq . '-' . bin2hex(random_bytes(3)), 'Testvertrag ' . $this->seq, $typeId, $ownerId, $status, $ownerId],
        );
        $contractId = $this->db->lastId();
        $versionId = $this->version($tenantId, $contractId, $ownerId, 1, null);
        $this->db->run('UPDATE contracts SET current_version_id = ? WHERE id = ?', [$versionId, $contractId]);
        return $contractId;
    }

    public function version(int $tenantId, int $contractId, int $userId, int $no, ?int $previous, string $text = 'Vertragstext'): int
    {
        $this->db->run(
            'INSERT INTO contract_versions (tenant_id, contract_id, version_no, previous_version_id, body_html, body_text, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$tenantId, $contractId, $no, $previous, '<p>' . $text . '</p>', $text, $userId],
        );
        return $this->db->lastId();
    }

    /** Sperrt die aktuelle Version, setzt sie als freigegebene Version und den Status auf "approved". */
    public function approve(int $contractId, string $status = 'approved'): int
    {
        $row = $this->db->one('SELECT current_version_id, body_text FROM contracts c JOIN contract_versions v ON v.id = c.current_version_id WHERE c.id = ?', [$contractId]);
        $versionId = (int) $row['current_version_id'];
        $this->db->run(
            "UPDATE contract_versions SET state = 'locked', locked_at = UTC_TIMESTAMP(6), content_hash = ? WHERE id = ?",
            [hash('sha256', (string) $row['body_text']), $versionId],
        );
        $this->db->run('UPDATE contracts SET approved_version_id = ?, status = ? WHERE id = ?', [$versionId, $status, $contractId]);
        return $versionId;
    }

    /** @param list<int> $contractIds @param list<string> $actions */
    public function mandate(int $tenantId, int $userId, string $type, array $contractIds, array $actions, string $from, string $until, int $responsibleId, string $status = 'active'): int
    {
        $this->db->run(
            'INSERT INTO external_mandates (tenant_id, mandate_type, user_id, responsible_user_id, allowed_actions, valid_from, valid_until, status, revoked_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tenantId, $type, $userId, $responsibleId, json_encode($actions), $from, $until, $status, $status === 'revoked' ? $from : null, $responsibleId],
        );
        $id = $this->db->lastId();
        foreach ($contractIds as $cid) {
            $this->db->run('INSERT INTO external_mandate_contracts (mandate_id, contract_id) VALUES (?,?)', [$id, $cid]);
        }
        return $id;
    }

    public function document(int $tenantId, int $contractId, ?int $versionId, string $kind = 'generated', bool $external = true, ?int $uploader = null): int
    {
        $this->seq++;
        $this->db->run(
            'INSERT INTO documents (tenant_id, contract_id, contract_version_id, kind, storage_key, original_filename, mime_type, size_bytes, sha256, visible_to_external, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$tenantId, $contractId, $versionId, $kind, bin2hex(random_bytes(20)), 'x.pdf', 'application/pdf', 10, hash('sha256', 'x' . $this->seq), $external ? 1 : 0, $uploader],
        );
        return $this->db->lastId();
    }
}
