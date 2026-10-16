<?php

declare(strict_types=1);

namespace App\Modules\Auditor;

use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\PlainPdfRenderer;
use App\Services\AccessControl;

/**
 * Revisionsportal: lesender, protokollierter Zugriff auf freigegebene Versionen, Verlauf und Nachweise.
 * Jede Methode prüft das Mandat (Aktion, Gültigkeit, Vertrag) über AccessControl::contract().
 * Prüfvermerke werden getrennt von Vertragskommentaren gespeichert und sind für Vertragspartner unsichtbar.
 */
final class AuditorService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly PlainPdfRenderer $pdf,
    ) {
    }

    /** @return array<string,mixed> */
    public function view(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        $v = $this->db->one('SELECT id, version_no, content_hash, locked_at, body_html, change_note FROM contract_versions WHERE id = ? AND contract_id = ? AND tenant_id = ? AND state = \'locked\'', [(int) $c['approved_version_id'], $contractId, (int) $c['tenant_id']]);
        $this->audit->log('auditor.viewed_contract', 'success', (int) $c['tenant_id'], $p->userId, 'contract', $contractId, ['version_no' => $v['version_no'] ?? null]);
        return [
            'contract' => array_intersect_key($c, array_flip(['id', 'contract_number', 'title', 'status', 'start_date', 'end_date', 'value_amount', 'value_currency'])),
            'version' => $v,
            'versions' => $this->db->all('SELECT id, version_no, content_hash, locked_at FROM contract_versions WHERE contract_id = ? AND tenant_id = ? AND state = \'locked\' ORDER BY version_no', [$contractId, (int) $c['tenant_id']]),
        ];
    }

    /** @return list<array<string,mixed>> Statusverlauf und Entscheide (ohne personenbezogene Metadaten über das Nötige hinaus) */
    public function history(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'history');
        $tid = (int) $c['tenant_id'];
        $events = $this->db->all(
            "SELECT occurred_at, action, outcome, JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.from')) AS from_status, JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.to')) AS to_status
               FROM audit_events WHERE tenant_id = ? AND object_type = 'contract' AND object_id = ? AND action IN ('contract.created','contract.status_changed','contract.archived','contract.amendment_created') ORDER BY seq",
            [$tid, $contractId],
        );
        $this->audit->log('auditor.viewed_history', 'success', $tid, $p->userId, 'contract', $contractId);
        return $events;
    }

    /** @return array{approvals:list<array<string,mixed>>,signatures:list<array<string,mixed>>,chain:array<string,mixed>} */
    public function evidence(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'evidence');
        $tid = (int) $c['tenant_id'];
        $approvals = $this->db->all(
            'SELECT a.step_no, a.status, a.decided_at, a.version_hash, v.version_no, u.display_name AS approver FROM approvals a
               JOIN contract_versions v ON v.id = a.contract_version_id JOIN users u ON u.id = a.approver_user_id
              WHERE a.contract_id = ? AND a.tenant_id = ? ORDER BY a.contract_version_id, a.step_no',
            [$contractId, $tid],
        );
        $signatures = $this->db->all(
            "SELECT sr.id, sr.provider, sr.mode, sr.requested_level, sr.status, sr.document_sha256, sr.created_at,
                    (SELECT GROUP_CONCAT(CONCAT(se.evidence_type, ':', se.achieved_level, ':', se.validation_status, IF(se.is_sandbox = 1, ':TEST', '')) SEPARATOR ', ') FROM signature_evidence se WHERE se.signature_request_id = sr.id) AS evidence
               FROM signature_requests sr WHERE sr.contract_id = ? AND sr.tenant_id = ? ORDER BY sr.id",
            [$contractId, $tid],
        );
        $chain = $this->audit->verify();
        $this->audit->log('auditor.viewed_evidence', 'success', $tid, $p->userId, 'contract', $contractId);
        return ['approvals' => $approvals, 'signatures' => $signatures, 'chain' => ['ok' => (bool) ($chain['ok'] ?? false)]];
    }

    public function annotate(Principal $p, int $contractId, string $text): int
    {
        $c = $this->ac->contract($p, $contractId, 'annotate');
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4000) {
            throw new ValidationException(['text' => 'Prüfvermerk: 1–4000 Zeichen.']);
        }
        $this->db->run("INSERT INTO contract_comments (tenant_id, contract_id, author_user_id, visibility, kind, body) VALUES (?,?,?, 'internal', 'audit_note', ?)", [(int) $c['tenant_id'], $contractId, $p->userId, $text]);
        $id = $this->db->lastId();
        $this->audit->log('auditor.annotated', 'success', (int) $c['tenant_id'], $p->userId, 'comment', $id, ['contract_id' => $contractId]);
        return $id;
    }

    /**
     * Prüfbericht als PDF (Vertragsstammdaten, freigegebene Version mit Hash, Freigaben, Signaturen, Prüfvermerke).
     * @param list<int> $contractIds
     * @return array{bytes:string,sha256:string}
     */
    public function exportReport(Principal $p, array $contractIds): array
    {
        if ($p->userType !== 'auditor' || !$p->can('audit.export_report')) {
            throw new ForbiddenException();
        }
        $contractIds = array_values(array_unique(array_map('intval', $contractIds)));
        if ($contractIds === [] || count($contractIds) > 50) {
            throw new ValidationException(['contracts' => '1 bis 50 Verträge auswählen.']);
        }
        $blocks = [['type' => 'p', 'text' => 'Prüfbericht – erstellt für ' . $p->displayName . ' am ' . gmdate('d.m.Y H:i') . ' UTC', 'n' => null]];
        $tenantForAudit = null;
        foreach ($contractIds as $cid) {
            $c = $this->ac->contract($p, $cid, 'export_report');
            $tid = (int) $c['tenant_id'];
            $tenantForAudit ??= $tid;
            $ver = $this->db->one('SELECT version_no, content_hash FROM contract_versions WHERE id = ? AND tenant_id = ?', [(int) $c['approved_version_id'], $tid]);
            $blocks[] = ['type' => 'h1', 'text' => $c['contract_number'] . ' – ' . $c['title'], 'n' => null];
            $blocks[] = ['type' => 'p', 'text' => 'Status: ' . $c['status'] . ' | Freigegebene Version: ' . ($ver['version_no'] ?? '–') . ' | SHA-256: ' . ($ver['content_hash'] ?? '–'), 'n' => null];
            foreach ($this->db->all("SELECT a.step_no, a.status, a.decided_at, u.display_name FROM approvals a JOIN users u ON u.id = a.approver_user_id WHERE a.contract_id = ? AND a.tenant_id = ? AND a.status <> 'invalidated' ORDER BY a.step_no", [$cid, $tid]) as $a) {
                $blocks[] = ['type' => 'li', 'text' => 'Freigabe Schritt ' . $a['step_no'] . ': ' . $a['status'] . ' (' . $a['display_name'] . ', ' . ($a['decided_at'] ?? 'offen') . ')', 'n' => null];
            }
            foreach ($this->db->all('SELECT provider, mode, requested_level, status FROM signature_requests WHERE contract_id = ? AND tenant_id = ? ORDER BY id', [$cid, $tid]) as $s) {
                $blocks[] = ['type' => 'li', 'text' => 'Signatur: ' . $s['provider'] . ($s['mode'] === 'sandbox' ? ' (TESTMODUS, nicht rechtsgültig)' : '') . ', Stufe ' . $s['requested_level'] . ', Status ' . $s['status'], 'n' => null];
            }
            foreach ($this->db->all("SELECT body, created_at FROM contract_comments WHERE contract_id = ? AND tenant_id = ? AND kind = 'audit_note' AND author_user_id = ? ORDER BY id", [$cid, $tid, $p->userId]) as $n) {
                $blocks[] = ['type' => 'p', 'text' => 'Prüfvermerk (' . $n['created_at'] . '): ' . $n['body'], 'n' => null];
            }
        }
        $blocks[] = ['type' => 'p', 'text' => 'Hinweis: Dieser Bericht gibt Daten der Plattform wieder. Er ersetzt weder eine juristische Prüfung noch die unabhängige Validierung elektronischer Signaturen.', 'n' => null];
        $bytes = $this->pdf->render('Prüfbericht', $blocks);
        $sha = hash('sha256', $bytes);
        $this->audit->log('auditor.report_exported', 'success', $tenantForAudit, $p->userId, 'report', null, ['contracts' => count($contractIds), 'sha256' => $sha]);
        return ['bytes' => $bytes, 'sha256' => $sha];
    }
}
