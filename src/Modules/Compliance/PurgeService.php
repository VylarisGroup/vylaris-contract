<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Services\AccessControl;

/**
 * Endgültige Löschung eines Vertrags samt Versionen, Dokumenten und Nachweisen NACH Ablauf der Aufbewahrung.
 * Nur über dieses dokumentierte Verfahren (setzt @purge_authorized für die Integritäts-Trigger).
 * Voraussetzungen werden in RetentionService::evaluate() geprüft (archiviert, Frist abgelaufen, geprüfte Regel
 * mit Aktion "delete", keine rechtliche Sperre). Das Audit-Protokoll bleibt erhalten (nur Nummer/Hash, kein Inhalt).
 */
final class PurgeService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly RetentionService $retention,
        private readonly DocumentStore $store,
    ) {
    }

    public function purge(Principal $p, int $contractId, string $confirmNumber): void
    {
        $this->ac->require($p, 'retention.manage');
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        $tenantId = $p->tenantId;
        $c = $this->db->one('SELECT * FROM contracts WHERE id = ? AND tenant_id = ?', [$contractId, $tenantId]);
        if ($c === null) {
            throw new NotFoundException();
        }
        if (!hash_equals((string) $c['contract_number'], trim($confirmNumber))) {
            throw new ValidationException(['confirm' => 'Zur Bestätigung die Vertragsnummer exakt eingeben.']);
        }
        $e = $this->retention->evaluate($tenantId, $contractId);
        if (!$e['deletable']) {
            $this->audit->log('retention.purge_refused', 'denied', $tenantId, $p->userId, 'contract', $contractId, ['blocked_by' => $e['blocked_by']]);
            throw new BusinessRuleException('Löschung nicht zulässig: ' . implode(' ', $e['blocked_by']));
        }
        $hashes = array_column($this->db->all('SELECT content_hash FROM contract_versions WHERE contract_id = ? AND content_hash IS NOT NULL ORDER BY version_no', [$contractId]), 'content_hash');
        $docs = $this->db->all('SELECT id, storage_key, tenant_id FROM documents WHERE contract_id = ?', [$contractId]);
        $files = [];
        foreach ($docs as $d) {
            try {
                $files[] = $this->store->absolutePath($d);
            } catch (\Throwable) {
            }
        }
        try {
            $this->db->transaction(function () use ($contractId, $tenantId): void {
                $this->db->run('SET @purge_authorized = 1');
                foreach ([
                    'DELETE se FROM signature_evidence se JOIN signature_requests sr ON sr.id = se.signature_request_id WHERE sr.contract_id = ?',
                    'DELETE ev FROM signature_events ev JOIN signature_requests sr ON sr.id = ev.signature_request_id WHERE sr.contract_id = ?',
                    'DELETE FROM signature_requests WHERE contract_id = ?',
                    'DELETE FROM notary_cases WHERE contract_id = ?',
                    'DELETE FROM approvals WHERE contract_id = ?',
                    'DELETE FROM contract_comments WHERE contract_id = ?',
                    'DELETE FROM contract_tasks WHERE contract_id = ?',
                    'DELETE FROM contract_deadlines WHERE contract_id = ?',
                    'DELETE FROM external_mandate_contracts WHERE contract_id = ?',
                    'DELETE FROM contract_acl WHERE contract_id = ?',
                    'DELETE FROM contract_parties WHERE contract_id = ?',
                    'DELETE FROM contract_tags WHERE contract_id = ?',
                    'DELETE FROM custom_field_values WHERE contract_id = ?',
                    'DELETE FROM legal_holds WHERE contract_id = ?',
                    'DELETE FROM documents WHERE contract_id = ?',
                    'UPDATE contracts SET current_version_id = NULL, approved_version_id = NULL WHERE id = ?',
                    'DELETE FROM contract_versions WHERE contract_id = ?',
                    'DELETE FROM contracts WHERE id = ?',
                ] as $sql) {
                    $this->db->run($sql, [$contractId]);
                }
            });
        } finally {
            $this->db->run('SET @purge_authorized = NULL');
        }
        foreach ($files as $f) {
            @unlink($f);
        }
        $this->audit->log('retention.purged', 'success', $tenantId, $p->userId, 'contract', $contractId, [
            'contract_number' => $c['contract_number'], 'policy_id' => (int) $e['policy']['id'], 'retain_until' => $e['retain_until'],
            'versions_digest' => hash('sha256', implode(',', $hashes)), 'documents' => count($docs),
        ]);
    }
}
