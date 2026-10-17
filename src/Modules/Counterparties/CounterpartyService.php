<?php

declare(strict_types=1);

namespace App\Modules\Counterparties;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/**
 * Vertragsparteien (Unternehmen, Vereine, Organisationen, Personen). Datensparsamkeit: nur der Name ist Pflicht.
 * Als "sensibel" markierte Datensätze zeigen Kontakt- und Adressdaten nur Benutzern mit sensitive.access.
 */
final class CounterpartyService
{
    /** Zustand der Anbindung: connected | ended | legacy (von Hand angelegter Altbestand). */
    private const LINK_SQL = "CASE WHEN cp.linked_tenant_id IS NULL THEN 'legacy' WHEN EXISTS (SELECT 1 FROM tenant_connections n WHERE n.status = 'active' AND ((n.requester_tenant_id = cp.tenant_id AND n.target_tenant_id = cp.linked_tenant_id) OR (n.target_tenant_id = cp.tenant_id AND n.requester_tenant_id = cp.linked_tenant_id))) THEN 'connected' ELSE 'ended' END";

    public function __construct(private readonly Database $db, private readonly AccessControl $ac, private readonly AuditLogger $audit)
    {
    }

    /** @param array<string,mixed> $in */
    public function save(Principal $p, array $in, ?int $id = null): int
    {
        $tenantId = $this->tenant($p);
        $this->ac->require($p, $id === null ? 'contract.create' : 'contract.edit');
        if ($id === null) {
            throw new BusinessRuleException('Gegenparteien werden nicht mehr von Hand angelegt, sondern über den Mandanten-Code verbunden (Gegenparteien → Mit Mandant verbinden).');
        }
        if ($this->db->value('SELECT linked_tenant_id FROM counterparties WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) !== null) {
            throw new BusinessRuleException('Verbundene Gegenparteien werden vom verbundenen Mandanten selbst gepflegt und können hier nicht geändert werden.');
        }
        $type = (string) ($in['party_type'] ?? 'company');
        $name = trim((string) ($in['name'] ?? ''));
        $err = [];
        if (!in_array($type, ['company', 'association', 'organization', 'person'], true)) {
            $err['party_type'] = 'Ungültige Art.';
        }
        if ($name === '' || mb_strlen($name) > 255) {
            $err['name'] = 'Name erforderlich (max. 255 Zeichen).';
        }
        $email = trim((string) ($in['contact_email'] ?? ''));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190)) {
            $err['contact_email'] = 'Ungültige E-Mail-Adresse.';
        }
        $country = strtoupper(trim((string) ($in['country'] ?? '')));
        if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $err['country'] = 'Ländercode mit 2 Buchstaben (z. B. CH).';
        }
        $sensitive = !empty($in['sensitive_data']) ? 1 : 0;
        if ($sensitive === 1 && !$p->can('sensitive.access')) {
            throw new ForbiddenException();
        }
        $opt = static fn (string $k, int $max): ?string => ($v = mb_substr(trim((string) ($in[$k] ?? '')), 0, $max)) !== '' ? $v : null;
        if ($err !== []) {
            throw new ValidationException($err);
        }
        $vals = [$type, $name, $opt('legal_form', 80), $opt('registration_id', 80), $email !== '' ? $email : null, $opt('address_line', 255), $opt('postal_code', 20), $opt('city', 120), $country !== '' ? $country : null, $sensitive];
        if (false) {
            $this->db->run('INSERT INTO counterparties (tenant_id, party_type, name, legal_form, registration_id, contact_email, address_line, postal_code, city, country, sensitive_data) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$tenantId, ...$vals]);
            $id = $this->db->lastId();
            $this->audit->log('counterparty.created', 'success', $tenantId, $p->userId, 'counterparty', $id);
            return $id;
        }
        $row = $this->db->one('SELECT sensitive_data FROM counterparties WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
        if ($row === null) {
            throw new NotFoundException();
        }
        if ((int) $row['sensitive_data'] === 1 && !$p->can('sensitive.access')) {
            throw new ForbiddenException();
        }
        $this->db->run('UPDATE counterparties SET party_type=?, name=?, legal_form=?, registration_id=?, contact_email=?, address_line=?, postal_code=?, city=?, country=?, sensitive_data=? WHERE id=? AND tenant_id=?', [...$vals, $id, $tenantId]);
        $this->audit->log('counterparty.updated', 'success', $tenantId, $p->userId, 'counterparty', $id);
        return $id;
    }

    /** @return array<string,mixed> */
    public function get(Principal $p, int $id): array
    {
        $tenantId = $this->tenant($p);
        $this->ac->require($p, 'contract.view');
        $r = $this->db->one('SELECT cp.*, ' . self::LINK_SQL . ' AS link_state FROM counterparties cp WHERE cp.id = ? AND cp.tenant_id = ?', [$id, $tenantId]);
        if ($r === null) {
            throw new NotFoundException();
        }
        return $this->mask($p, $r);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p, string $q = ''): array
    {
        $tenantId = $this->tenant($p);
        $this->ac->require($p, 'contract.view');
        $sql = 'SELECT cp.*, ' . self::LINK_SQL . ' AS link_state FROM counterparties cp WHERE cp.tenant_id = ? AND cp.archived_at IS NULL';
        $params = [$tenantId];
        if (trim($q) !== '') {
            $sql .= ' AND cp.name LIKE ?';
            $params[] = '%' . addcslashes(trim($q), '\\%_') . '%';
        }
        return array_map(fn (array $r): array => $this->mask($p, $r), $this->db->all($sql . ' ORDER BY cp.name LIMIT 200', $params));
    }

    /**
     * Auswahl für neue Vertragsparteien: nur aktiv verbundene Mandanten (plus bereits eingetragene Parteien des bearbeiteten Vertrags).
     * @param list<int> $keepIds
     * @return list<array<string,mixed>>
     */
    public function selectable(Principal $p, array $keepIds = []): array
    {
        return array_values(array_filter($this->list($p), static fn (array $r): bool => $r['link_state'] === 'connected' || in_array((int) $r['id'], $keepIds, true)));
    }

    public function archive(Principal $p, int $id): void
    {
        $tenantId = $this->tenant($p);
        $this->ac->require($p, 'contract.edit');
        $open = $this->db->value("SELECT COUNT(*) FROM contract_parties cp JOIN contracts c ON c.id = cp.contract_id WHERE cp.counterparty_id = ? AND cp.tenant_id = ? AND c.status IN ('draft','review','approved','signature','active')", [$id, $tenantId]);
        if ((int) $open > 0) {
            throw new BusinessRuleException('Die Partei ist noch in laufenden Verträgen eingetragen.');
        }
        if ($this->db->execute('UPDATE counterparties SET archived_at = UTC_TIMESTAMP(6) WHERE id = ? AND tenant_id = ?', [$id, $tenantId]) === 0) {
            throw new NotFoundException();
        }
        $this->audit->log('counterparty.archived', 'success', $tenantId, $p->userId, 'counterparty', $id);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function mask(Principal $p, array $r): array
    {
        if ((int) $r['sensitive_data'] === 1 && !$p->can('sensitive.access')) {
            foreach (['contact_email', 'address_line', 'postal_code', 'city', 'registration_id'] as $f) {
                $r[$f] = null;
            }
            $r['masked'] = true;
        }
        return $r;
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }
}
