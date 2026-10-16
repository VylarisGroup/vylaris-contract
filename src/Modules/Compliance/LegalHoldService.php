<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/** Rechtliche Sperren (Legal Hold): verhindern Löschung/Anonymisierung betroffener Verträge und Parteien. */
final class LegalHoldService
{
    public function __construct(private readonly Database $db, private readonly AccessControl $ac, private readonly AuditLogger $audit)
    {
    }

    public function place(Principal $p, ?int $contractId, ?int $counterpartyId, string $reason): int
    {
        $this->ac->require($p, 'legalhold.manage');
        $tenantId = $this->tenant($p);
        if (($contractId === null) === ($counterpartyId === null)) {
            throw new ValidationException(['target' => 'Genau ein Vertrag ODER eine Vertragspartei angeben.']);
        }
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => 'Bitte einen Grund (max. 1000 Zeichen) angeben.']);
        }
        if ($contractId !== null && !$this->db->value('SELECT 1 FROM contracts WHERE id = ? AND tenant_id = ?', [$contractId, $tenantId])) {
            throw new NotFoundException();
        }
        if ($counterpartyId !== null && !$this->db->value('SELECT 1 FROM counterparties WHERE id = ? AND tenant_id = ?', [$counterpartyId, $tenantId])) {
            throw new NotFoundException();
        }
        $this->db->run('INSERT INTO legal_holds (tenant_id, contract_id, counterparty_id, reason, placed_by) VALUES (?,?,?,?,?)', [$tenantId, $contractId, $counterpartyId, trim($reason), $p->userId]);
        $id = $this->db->lastId();
        $this->audit->log('legalhold.placed', 'success', $tenantId, $p->userId, 'legal_hold', $id, ['contract_id' => $contractId, 'counterparty_id' => $counterpartyId]);
        return $id;
    }

    public function release(Principal $p, int $holdId, string $reason): void
    {
        $this->ac->require($p, 'legalhold.manage');
        $tenantId = $this->tenant($p);
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Bitte einen Grund für die Aufhebung angeben.']);
        }
        $h = $this->db->one('SELECT * FROM legal_holds WHERE id = ? AND tenant_id = ?', [$holdId, $tenantId]);
        if ($h === null) {
            throw new NotFoundException();
        }
        if ($h['released_at'] !== null) {
            throw new BusinessRuleException('Die Sperre wurde bereits aufgehoben.');
        }
        $this->db->run('UPDATE legal_holds SET released_by = ?, released_at = UTC_TIMESTAMP(6), release_reason = ? WHERE id = ?', [$p->userId, trim($reason), $holdId]);
        $this->audit->log('legalhold.released', 'success', $tenantId, $p->userId, 'legal_hold', $holdId, ['reason' => mb_substr($reason, 0, 300)]);
    }

    public function contractHeld(int $tenantId, int $contractId): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM legal_holds h WHERE h.tenant_id = ? AND h.released_at IS NULL AND (h.contract_id = ?
               OR h.counterparty_id IN (SELECT counterparty_id FROM contract_parties WHERE contract_id = ? AND tenant_id = ?)) LIMIT 1',
            [$tenantId, $contractId, $contractId, $tenantId],
        );
    }

    public function counterpartyHeld(int $tenantId, int $counterpartyId): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM legal_holds h WHERE h.tenant_id = ? AND h.released_at IS NULL AND (h.counterparty_id = ?
               OR h.contract_id IN (SELECT contract_id FROM contract_parties WHERE counterparty_id = ? AND tenant_id = ?)) LIMIT 1',
            [$tenantId, $counterpartyId, $counterpartyId, $tenantId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p, bool $onlyActive = true): array
    {
        $this->ac->require($p, 'legalhold.manage');
        return $this->db->all(
            'SELECT h.*, c.contract_number FROM legal_holds h LEFT JOIN contracts c ON c.id = h.contract_id WHERE h.tenant_id = ?' . ($onlyActive ? ' AND h.released_at IS NULL' : '') . ' ORDER BY h.id DESC',
            [$this->tenant($p)],
        );
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }
}
