<?php

declare(strict_types=1);

namespace App\Modules\Approvals;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\StatusMachine;
use App\Modules\Contracts\WorkflowService;
use App\Services\AccessControl;

/**
 * Mehrstufige Freigabe. Eine Freigabe bezieht sich auf EINE konkrete, gesperrte Version und
 * deren Inhalts-Hash. Wird die Version geändert (neue Version), werden Freigaben ungültig.
 */
final class ApprovalService
{
    private const MAX_STEPS = 5;
    private const MAX_APPROVERS_PER_STEP = 5;

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly WorkflowService $workflow,
    ) {
    }

    public static function hashBody(string $bodyHtml): string
    {
        return hash('sha256', $bodyHtml);
    }

    /**
     * Fordert Prüfung/Freigabe an: sperrt die aktuelle Entwurfsversion unveränderlich und legt
     * die Genehmigungsschritte an. Schritte werden nacheinander, Genehmigende eines Schritts
     * parallel abgearbeitet (alle müssen zustimmen).
     *
     * @param list<list<int>> $steps
     * @return int gesperrte Versions-ID
     */
    public function request(Principal $p, int $contractId, array $steps): int
    {
        $c0 = $this->ac->contract($p, $contractId, 'request_approval');
        $tenantId = (int) $c0['tenant_id'];
        $steps = $this->validateSteps($tenantId, $steps);

        return $this->db->transaction(function () use ($p, $tenantId, $contractId, $steps): int {
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($c === null) {
                throw new NotFoundException();
            }
            if ($c['status'] !== StatusMachine::DRAFT) {
                throw new BusinessRuleException('Eine Freigabe kann nur für Entwürfe angefordert werden.');
            }
            $v = $c['current_version_id'] === null ? null : $this->db->one('SELECT * FROM contract_versions WHERE id = ? FOR UPDATE', [(int) $c['current_version_id']]);
            if ($v === null || trim((string) $v['body_text']) === '') {
                throw new BusinessRuleException('Der Vertrag hat noch keinen Vertragstext.');
            }
            if ($v['state'] === 'draft') {
                $hash = self::hashBody((string) $v['body_html']);
                $this->db->run("UPDATE contract_versions SET state = 'locked', locked_at = UTC_TIMESTAMP(6), content_hash = ? WHERE id = ? AND state = 'draft'", [$hash, (int) $v['id']]);
            } else {
                $hash = (string) $v['content_hash'];
            }
            foreach ($steps as $stepNo => $approvers) {
                foreach ($approvers as $uid) {
                    if ($uid === (int) $v['created_by']) {
                        throw new BusinessRuleException('Die Person, die diese Version erstellt hat, darf sie nicht selbst freigeben (Vier-Augen-Prinzip).');
                    }
                    $this->db->run(
                        'INSERT INTO approvals (tenant_id, contract_id, contract_version_id, step_no, approver_user_id, version_hash) VALUES (?,?,?,?,?,?)',
                        [$tenantId, $contractId, (int) $v['id'], $stepNo + 1, $uid, $hash],
                    );
                }
            }
            $this->workflow->move($contractId, StatusMachine::REVIEW, $p->userId, 'Freigabe angefordert', ['version_id' => (int) $v['id'], 'steps' => count($steps)]);
            $this->audit->log('approval.requested', 'success', $tenantId, $p->userId, 'contract_version', (int) $v['id'], ['steps' => count($steps), 'content_hash' => $hash]);
            return (int) $v['id'];
        });
    }

    /** @return string 'approved'|'rejected'|'pending' – Gesamtergebnis nach dieser Entscheidung */
    public function decide(Principal $p, int $approvalId, string $decision, string $comment = ''): string
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ValidationException(['decision' => 'Ungültige Entscheidung.']);
        }
        $comment = trim($comment);
        if ($decision === 'reject' && $comment === '') {
            throw new ValidationException(['comment' => 'Bitte eine Begründung für die Ablehnung angeben.']);
        }
        $a0 = $this->db->one('SELECT * FROM approvals WHERE id = ? AND approver_user_id = ?', [$approvalId, $p->userId]);
        if ($a0 === null) {
            $this->audit->log('access.denied', 'denied', $p->tenantId, $p->userId, 'approval', $approvalId, ['reason' => 'not_assigned_approver']);
            throw new NotFoundException();
        }
        $this->ac->contract($p, (int) $a0['contract_id'], 'approve');
        $tenantId = (int) $a0['tenant_id'];

        // Integritätsverletzungen werden ausserhalb der Transaktion protokolliert, damit der
        // Rollback den Audit-Eintrag nicht mit zurückrollt.
        $violationVersionId = null;
        try {
            return $this->decideInTransaction($p, $approvalId, $decision, $comment, $tenantId, $violationVersionId);
        } catch (BusinessRuleException $e) {
            if ($violationVersionId !== null) {
                $this->audit->log('integrity.violation', 'failure', $tenantId, $p->userId, 'contract_version', $violationVersionId, ['where' => 'approval_decision']);
            }
            throw $e;
        }
    }

    private function decideInTransaction(Principal $p, int $approvalId, string $decision, string $comment, int $tenantId, ?int &$violationVersionId): string
    {
        return $this->db->transaction(function () use ($p, $approvalId, $decision, $comment, $tenantId, &$violationVersionId): string {
            $a = $this->db->one('SELECT * FROM approvals WHERE id = ? FOR UPDATE', [$approvalId]);
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [(int) $a['contract_id']]);
            if ($a['status'] !== 'pending') {
                throw new BusinessRuleException('Diese Freigabe wurde bereits entschieden oder ist nicht mehr gültig.');
            }
            if ($c['status'] !== StatusMachine::REVIEW) {
                throw new BusinessRuleException('Der Vertrag befindet sich nicht in Prüfung.');
            }
            if ((int) $c['current_version_id'] !== (int) $a['contract_version_id']) {
                throw new BusinessRuleException('Die Freigabe bezieht sich nicht auf die aktuelle Version.');
            }
            $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ?', [(int) $a['contract_version_id']]);
            // Integritätsprüfung: gesperrte Version muss noch dem freigegebenen Hash entsprechen.
            if ($v === null || $v['state'] !== 'locked'
                || !hash_equals((string) $a['version_hash'], (string) $v['content_hash'])
                || !hash_equals((string) $a['version_hash'], self::hashBody((string) $v['body_html']))) {
                $violationVersionId = (int) $a['contract_version_id'];
                throw new BusinessRuleException('Integritätsprüfung fehlgeschlagen: Der Versionsinhalt stimmt nicht mit dem Freigabestand überein.');
            }
            $earlier = (int) $this->db->value(
                "SELECT COUNT(*) FROM approvals WHERE contract_version_id = ? AND step_no < ? AND status <> 'approved'",
                [(int) $a['contract_version_id'], (int) $a['step_no']],
            );
            if ($earlier > 0) {
                throw new BusinessRuleException('Vorherige Freigabeschritte sind noch nicht abgeschlossen.');
            }

            $new = $decision === 'approve' ? 'approved' : 'rejected';
            $this->db->run('UPDATE approvals SET status = ?, decision_comment = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ?', [$new, $comment === '' ? null : mb_substr($comment, 0, 1000), $approvalId]);
            $this->audit->log('approval.' . $new, 'success', $tenantId, $p->userId, 'approval', $approvalId, ['version_id' => (int) $a['contract_version_id'], 'step' => (int) $a['step_no']]);

            if ($new === 'rejected') {
                $this->db->run("UPDATE approvals SET status = 'invalidated' WHERE contract_version_id = ? AND status = 'pending'", [(int) $a['contract_version_id']]);
                $this->workflow->move((int) $c['id'], StatusMachine::REJECTED, $p->userId, 'Freigabe abgelehnt');
                return 'rejected';
            }
            $open = (int) $this->db->value("SELECT COUNT(*) FROM approvals WHERE contract_version_id = ? AND status = 'pending'", [(int) $a['contract_version_id']]);
            if ($open > 0) {
                return 'pending';
            }
            $this->db->run('UPDATE contracts SET approved_version_id = ? WHERE id = ?', [(int) $a['contract_version_id'], (int) $c['id']]);
            $this->workflow->move((int) $c['id'], StatusMachine::APPROVED, $p->userId, 'Alle Freigabeschritte erteilt', ['version_id' => (int) $a['contract_version_id']]);
            return 'approved';
        });
    }

    /** Zieht eine laufende Freigabeanfrage zurück (Vertrag -> zurückgezogen). */
    public function withdraw(Principal $p, int $contractId, string $reason): void
    {
        $c0 = $this->ac->contract($p, $contractId, 'request_approval');
        $this->db->transaction(function () use ($p, $contractId, $reason, $c0): void {
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($c['status'] !== StatusMachine::REVIEW) {
                throw new BusinessRuleException('Es läuft keine Freigabeanfrage.');
            }
            $this->db->run("UPDATE approvals SET status = 'invalidated' WHERE contract_id = ? AND status = 'pending'", [$contractId]);
            $this->workflow->move($contractId, StatusMachine::WITHDRAWN, $p->userId, mb_substr(trim($reason), 0, 300));
            $this->audit->log('approval.withdrawn', 'success', (int) $c0['tenant_id'], $p->userId, 'contract', $contractId);
        });
    }

    /**
     * Offene Genehmigungen, auf die der Handelnde Zugriff hat (für Dashboard).
     * @return list<array<string,mixed>>
     */
    public function openFor(Principal $p): array
    {
        if ($p->tenantId === null || $p->isExternal()) {
            return [];
        }
        if ($p->can('approval.decide')) {
            return $this->db->all(
                "SELECT a.id, a.step_no, a.created_at, c.id AS contract_id, c.contract_number, c.title
                   FROM approvals a JOIN contracts c ON c.id = a.contract_id AND c.tenant_id = a.tenant_id
                  WHERE a.tenant_id = ? AND a.approver_user_id = ? AND a.status = 'pending' AND c.status = 'review'
                    AND NOT EXISTS (SELECT 1 FROM approvals e WHERE e.contract_version_id = a.contract_version_id AND e.step_no < a.step_no AND e.status <> 'approved')
                  ORDER BY a.created_at",
                [$p->tenantId, $p->userId],
            );
        }
        return [];
    }

    /** @param list<list<int>> $steps @return list<list<int>> */
    private function validateSteps(int $tenantId, array $steps): array
    {
        $steps = array_values(array_filter($steps, static fn ($s): bool => is_array($s) && $s !== []));
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new ValidationException(['steps' => 'Bitte 1 bis ' . self::MAX_STEPS . ' Freigabeschritte angeben.']);
        }
        $clean = [];
        $seen = [];
        foreach ($steps as $i => $step) {
            if (count($step) > self::MAX_APPROVERS_PER_STEP) {
                throw new ValidationException(['steps' => 'Maximal ' . self::MAX_APPROVERS_PER_STEP . ' Genehmigende je Schritt.']);
            }
            $ids = [];
            foreach ($step as $uid) {
                $uid = (int) $uid;
                if (isset($seen[$uid])) {
                    throw new ValidationException(['steps' => 'Eine Person kann nur einmal je Vertrag zugeordnet werden.']);
                }
                $ok = $this->db->one(
                    "SELECT u.id FROM users u
                       JOIN tenant_memberships tm ON tm.user_id = u.id AND tm.tenant_id = ? AND tm.status = 'active'
                       JOIN role_permissions rp ON rp.role_id = tm.role_id
                       JOIN permissions pm ON pm.id = rp.permission_id AND pm.code = 'approval.decide'
                      WHERE u.id = ? AND u.status = 'active' AND u.user_type = 'internal' LIMIT 1",
                    [$tenantId, $uid],
                );
                if ($ok === null) {
                    throw new ValidationException(['steps' => 'Schritt ' . ($i + 1) . ': Die gewählte Person darf keine Freigaben erteilen.']);
                }
                $seen[$uid] = true;
                $ids[] = $uid;
            }
            $clean[] = $ids;
        }
        return $clean;
    }
}
