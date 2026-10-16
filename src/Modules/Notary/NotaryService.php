<?php

declare(strict_types=1);

namespace App\Modules\Notary;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\ContractDocumentService;
use App\Modules\Documents\DocumentStore;
use App\Modules\Mandates\MandateService;
use App\Services\AccessControl;

/**
 * Notariatsfälle. Ablauf: prepared → provided → in_progress → (inquiry ↔ in_progress) → certified | rejected → completed.
 * Das bereitgestellte PDF wird mit Hash gebunden und kann danach nicht ersetzt werden (DB-Trigger);
 * Rückläufer (Beglaubigung, Urkunde) sind eigene Dokumente und ändern weder Vertragsversion noch Original.
 * Hinweis: Beglaubigung/Beurkundung ist ein Vorgang des Notariats ausserhalb der Plattform – die Plattform
 * dokumentiert nur Statusmeldungen und Rückläufer.
 */
final class NotaryService
{
    /** @var array<string,list<string>> erlaubte Übergänge für das Notariat */
    private const NOTARY_MOVES = ['provided' => ['in_progress'], 'in_progress' => ['inquiry', 'certified', 'rejected'], 'inquiry' => []];
    /** @var array<string,list<string>> erlaubte Übergänge für die Plattformseite */
    private const INTERNAL_MOVES = ['prepared' => ['provided'], 'inquiry' => ['in_progress'], 'certified' => ['completed'], 'rejected' => ['completed']];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly ContractDocumentService $documents,
        private readonly DocumentStore $store,
        private readonly MandateService $mandates,
    ) {
    }

    public function create(Principal $p, int $contractId, int $notaryUserId, string $office, ?int $responsibleUserId = null, string $remarks = ''): int
    {
        $c = $this->ac->contract($p, $contractId, 'notary');
        $tenantId = (int) $c['tenant_id'];
        if (!in_array($c['status'], ['approved', 'signature'], true) || $c['approved_version_id'] === null) {
            throw new BusinessRuleException('Ein Notariatsfall kann nur für einen freigegebenen Vertrag angelegt werden.');
        }
        $office = trim($office);
        if ($office === '' || mb_strlen($office) > 190) {
            throw new ValidationException(['notary_office' => 'Bitte das Notariat angeben.']);
        }
        $n = $this->db->one("SELECT id FROM users WHERE id = ? AND user_type = 'notary' AND status IN ('active','pending')", [$notaryUserId]);
        if ($n === null) {
            throw new ValidationException(['notary_user_id' => 'Unbekannte Notariatsperson.']);
        }
        $responsible = $responsibleUserId ?? $p->userId;
        if (!$this->db->value("SELECT 1 FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [$tenantId, $responsible])) {
            throw new ValidationException(['responsible_user_id' => 'Verantwortliche Person gehört nicht zum Mandanten.']);
        }
        $this->db->run(
            "INSERT INTO notary_cases (tenant_id, contract_id, contract_version_id, notary_user_id, notary_office, requested_by, responsible_user_id, status, remarks) VALUES (?,?,?,?,?,?,?, 'prepared', ?)",
            [$tenantId, $contractId, (int) $c['approved_version_id'], $notaryUserId, $office, $p->userId, $responsible, mb_substr($remarks, 0, 2000) ?: null],
        );
        $id = $this->db->lastId();
        $this->audit->log('notary.case_created', 'success', $tenantId, $p->userId, 'notary_case', $id, ['contract_id' => $contractId, 'version_id' => (int) $c['approved_version_id']]);
        return $id;
    }

    /** Stellt das PDF bereit: bindet Dokument und SHA-256, erteilt dem Notariat Zugriff (Mandat) und setzt "provided". */
    public function provide(Principal $p, int $caseId): void
    {
        $case = $this->internalCase($p, $caseId);
        $this->assertInternalMove($case, 'provided');
        $c = $this->ac->contract($p, (int) $case['contract_id'], 'notary');
        if ((int) $c['approved_version_id'] !== (int) $case['contract_version_id'] || !in_array($c['status'], ['approved', 'signature'], true)) {
            throw new BusinessRuleException('Die freigegebene Version hat sich geändert; bitte einen neuen Notariatsfall anlegen.');
        }
        $doc = $this->documents->signableDocument((int) $case['contract_id'], (int) $case['contract_version_id'], $p->userId);
        $this->store->verifyIntegrity($doc);
        $this->db->transaction(function () use ($p, $case, $doc): void {
            $this->mandates->ensureNotaryMandate($p, (int) $case['contract_id'], (int) $case['notary_user_id']);
            $this->db->run(
                "UPDATE notary_cases SET document_id = ?, provided_pdf_sha256 = ?, provided_at = UTC_TIMESTAMP(6), status = 'provided' WHERE id = ? AND status = 'prepared'",
                [(int) $doc['id'], (string) $doc['sha256'], (int) $case['id']],
            );
            $this->audit->log('notary.provided', 'success', (int) $case['tenant_id'], $p->userId, 'notary_case', (int) $case['id'], ['document_sha256' => (string) $doc['sha256']]);
        });
    }

    /** Statusmeldung des Notariats. */
    public function notaryUpdate(Principal $p, int $caseId, string $to, string $remark = ''): void
    {
        $case = $this->notaryCase($p, $caseId);
        $this->ac->contract($p, (int) $case['contract_id'], 'update_case');
        if (!in_array($to, self::NOTARY_MOVES[$case['status']] ?? [], true)) {
            $this->audit->log('notary.update_rejected', 'denied', (int) $case['tenant_id'], $p->userId, 'notary_case', $caseId, ['from' => $case['status'], 'to' => $to]);
            throw new BusinessRuleException('Dieser Statuswechsel ist nicht erlaubt.');
        }
        if (in_array($to, ['inquiry', 'rejected'], true) && trim($remark) === '') {
            throw new ValidationException(['remark' => 'Bitte eine Begründung bzw. Rückfrage angeben.']);
        }
        if ($to === 'certified' && !(int) $this->db->value("SELECT COUNT(*) FROM documents WHERE contract_id = ? AND kind = 'notary_return' AND uploaded_by = ? AND deleted_at IS NULL", [(int) $case['contract_id'], $p->userId])) {
            throw new BusinessRuleException('Bitte zuerst die Beglaubigung/Urkunde als Rückläufer hochladen.');
        }
        $this->setStatus($case, $to, $remark, $p->userId);
    }

    /** Antwort/Weiterbearbeitung der Plattformseite (Rückfrage beantwortet, Fall abschliessen). */
    public function internalUpdate(Principal $p, int $caseId, string $to, string $remark = ''): void
    {
        $case = $this->internalCase($p, $caseId);
        if ($to === 'provided') {
            $this->provide($p, $caseId);
            return;
        }
        $this->assertInternalMove($case, $to);
        $this->setStatus($case, $to, $remark, $p->userId);
    }

    /** Rückläufer des Notariats (neues Dokument; das bereitgestellte PDF bleibt unverändert). */
    public function uploadReturn(Principal $p, int $caseId, string $tmpPath, string $originalName): int
    {
        $case = $this->notaryCase($p, $caseId);
        $c = $this->ac->contract($p, (int) $case['contract_id'], 'upload_return');
        if (!in_array($case['status'], ['in_progress', 'inquiry', 'certified'], true)) {
            throw new BusinessRuleException('Im aktuellen Status können keine Rückläufer hochgeladen werden.');
        }
        $info = (new \App\Modules\Documents\UploadValidator())->validate($tmpPath, $originalName, ['pdf']);
        $bytes = (string) file_get_contents($tmpPath);
        $id = $this->store->storeBytes((int) $case['tenant_id'], (int) $c['id'], (int) $case['contract_version_id'], $bytes, $info['safe_name'], 'application/pdf', 'notary_return', $p->userId, false);
        $this->db->run('UPDATE notary_cases SET returned_at = UTC_TIMESTAMP(6) WHERE id = ?', [$caseId]);
        $this->audit->log('notary.return_uploaded', 'success', (int) $case['tenant_id'], $p->userId, 'notary_case', $caseId, ['document_id' => $id, 'sha256' => hash('sha256', $bytes)]);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(Principal $p): array
    {
        if ($p->userType === 'notary') {
            return $this->db->all(
                "SELECT nc.id, nc.contract_id, nc.status, nc.provided_at, nc.notary_office, c.contract_number, c.title
                   FROM notary_cases nc JOIN contracts c ON c.id = nc.contract_id AND c.tenant_id = nc.tenant_id
                  WHERE nc.notary_user_id = ? AND nc.status <> 'prepared' ORDER BY nc.id DESC",
                [$p->userId],
            );
        }
        if ($p->isExternal() || !$p->can('notary.manage') || $p->tenantId === null) {
            throw new ForbiddenException();
        }
        return $this->db->all(
            'SELECT nc.id, nc.contract_id, nc.status, nc.provided_at, nc.notary_office, c.contract_number, c.title
               FROM notary_cases nc JOIN contracts c ON c.id = nc.contract_id AND c.tenant_id = nc.tenant_id WHERE nc.tenant_id = ? ORDER BY nc.id DESC',
            [$p->tenantId],
        );
    }

    /** @return array<string,mixed> */
    public function get(Principal $p, int $caseId): array
    {
        $case = $p->userType === 'notary' ? $this->notaryCase($p, $caseId) : $this->internalCase($p, $caseId);
        $this->ac->contract($p, (int) $case['contract_id'], $p->userType === 'notary' ? 'view' : 'notary');
        $case['returns'] = $this->db->all("SELECT id, original_filename, sha256, created_at FROM documents WHERE contract_id = ? AND tenant_id = ? AND kind = 'notary_return' AND deleted_at IS NULL ORDER BY id", [(int) $case['contract_id'], (int) $case['tenant_id']]);
        return $case;
    }

    // ------------------------------------------------------------------ intern

    /** @return array<string,mixed> */
    private function internalCase(Principal $p, int $caseId): array
    {
        if ($p->isExternal() || $p->tenantId === null) {
            throw new NotFoundException();
        }
        $this->ac->require($p, 'notary.manage');
        $case = $this->db->one('SELECT * FROM notary_cases WHERE id = ? AND tenant_id = ?', [$caseId, $p->tenantId]);
        if ($case === null) {
            throw new NotFoundException();
        }
        return $case;
    }

    /** @return array<string,mixed> */
    private function notaryCase(Principal $p, int $caseId): array
    {
        if ($p->userType !== 'notary') {
            throw new NotFoundException();
        }
        $case = $this->db->one("SELECT * FROM notary_cases WHERE id = ? AND notary_user_id = ? AND status <> 'prepared'", [$caseId, $p->userId]);
        if ($case === null) {
            $this->audit->log('access.denied', 'denied', null, $p->userId, 'notary_case', $caseId, ['reason' => 'not_assigned']);
            throw new NotFoundException();
        }
        return $case;
    }

    /** @param array<string,mixed> $case */
    private function assertInternalMove(array $case, string $to): void
    {
        if (!in_array($to, self::INTERNAL_MOVES[$case['status']] ?? [], true)) {
            throw new BusinessRuleException('Dieser Statuswechsel ist nicht erlaubt.');
        }
    }

    /** @param array<string,mixed> $case */
    private function setStatus(array $case, string $to, string $remark, int $actorId): void
    {
        $this->db->transaction(function () use ($case, $to, $remark, $actorId): void {
            $n = $this->db->execute(
                'UPDATE notary_cases SET status = ?, remarks = CASE WHEN ? = \'\' THEN remarks ELSE ? END, completed_at = CASE WHEN ? = \'completed\' THEN UTC_TIMESTAMP(6) ELSE completed_at END WHERE id = ? AND status = ?',
                [$to, trim($remark), mb_substr(trim($remark), 0, 2000), $to, (int) $case['id'], $case['status']],
            );
            if ($n !== 1) {
                throw new BusinessRuleException('Der Fall wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            $this->audit->log('notary.status_' . $to, 'success', (int) $case['tenant_id'], $actorId, 'notary_case', (int) $case['id'], ['from' => $case['status']]);
        });
    }
}
