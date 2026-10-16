<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Modules\Audit\AuditLogger;

/**
 * Einziger Ort, an dem contracts.status geändert wird.
 *
 * WICHTIG: move() prüft die Statusmaschine, NICHT die Benutzerberechtigung. Aufrufer
 * (ContractService, ApprovalService, SignatureService, Cron) müssen vorher autorisieren.
 * Controller dürfen move() nicht direkt aufrufen.
 */
final class WorkflowService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed> aktualisierte Vertragszeile
     */
    public function move(int $contractId, string $to, ?int $actorId, string $reason = '', array $meta = []): array
    {
        // Ablehnungen werden AUSSERHALB der Transaktion protokolliert: Ein Rollback darf den
        // Audit-Eintrag über den abgewiesenen Versuch nicht mit zurückrollen.
        $rejected = null;
        try {
            return $this->moveInTransaction($contractId, $to, $actorId, $reason, $meta, $rejected);
        } catch (BusinessRuleException $e) {
            if ($rejected !== null) {
                $this->audit->log('contract.status_change_rejected', 'denied', $rejected['tenant_id'], $actorId, 'contract', $contractId, ['from' => $rejected['from'], 'to' => $to]);
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @param array{tenant_id:int,from:string}|null $rejected
     * @return array<string,mixed>
     */
    private function moveInTransaction(int $contractId, string $to, ?int $actorId, string $reason, array $meta, ?array &$rejected): array
    {
        return $this->db->transaction(function () use ($contractId, $to, $actorId, $reason, $meta, &$rejected): array {
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($c === null) {
                throw new NotFoundException();
            }
            $from = (string) $c['status'];
            if (!StatusMachine::canMove($from, $to)) {
                $rejected = ['tenant_id' => (int) $c['tenant_id'], 'from' => $from];
                throw new BusinessRuleException("Statuswechsel von „{$from}“ nach „{$to}“ ist nicht erlaubt.");
            }
            $this->db->run(
                'UPDATE contracts SET status = ?, archived_at = CASE WHEN ? = \'archived\' THEN UTC_TIMESTAMP(6) ELSE archived_at END WHERE id = ?',
                [$to, $to, $contractId],
            );
            $this->audit->log(
                'contract.status_changed',
                'success',
                (int) $c['tenant_id'],
                $actorId,
                'contract',
                $contractId,
                ['from' => $from, 'to' => $to, 'reason' => $reason] + $meta,
            );
            $c['status'] = $to;
            return $c;
        });
    }
}
