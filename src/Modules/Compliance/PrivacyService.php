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

/**
 * Auskunfts- und Löschanfragen (DSG / DSGVO-Prozess). Die Plattform liefert die Datengrundlage und Hinderungsgründe
 * (Aufbewahrung, rechtliche Sperre); die Entscheidung trifft ein Mensch und wird begründet protokolliert.
 * Fristen für die Beantwortung (z. B. 30 Tage) sind organisatorisch zu überwachen – hier nur als Hinweis gespeichert.
 */
final class PrivacyService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly LegalHoldService $holds,
        private readonly RetentionService $retention,
    ) {
    }

    public function open(Principal $p, string $type, string $subject, ?int $counterpartyId): int
    {
        $this->ac->require($p, 'privacy.manage');
        $tenantId = $this->tenant($p);
        if (!in_array($type, ['access', 'erasure'], true)) {
            throw new ValidationException(['type' => 'Ungültige Anfrageart.']);
        }
        if (trim($subject) === '' || mb_strlen($subject) > 500) {
            throw new ValidationException(['subject' => 'Bitte die betroffene Person/Beschreibung angeben.']);
        }
        if ($counterpartyId !== null && !$this->db->value('SELECT 1 FROM counterparties WHERE id = ? AND tenant_id = ?', [$counterpartyId, $tenantId])) {
            throw new ValidationException(['counterparty_id' => 'Unbekannte Vertragspartei.']);
        }
        $this->db->run('INSERT INTO deletion_requests (tenant_id, request_type, subject_description, counterparty_id, created_by) VALUES (?,?,?,?,?)', [$tenantId, $type, trim($subject), $counterpartyId, $p->userId]);
        $id = $this->db->lastId();
        $this->audit->log('privacy.request_opened', 'success', $tenantId, $p->userId, 'privacy_request', $id, ['type' => $type]);
        return $id;
    }

    /**
     * Datengrundlage für eine Auskunft (JSON). Besonders schützenswerte Daten nur mit Berechtigung sensitive.access.
     * @return array<string,mixed>
     */
    public function accessReport(Principal $p, int $requestId): array
    {
        $r = $this->request($p, $requestId);
        if ($r['counterparty_id'] === null) {
            throw new BusinessRuleException('Der Anfrage ist keine Vertragspartei zugeordnet.');
        }
        $tenantId = (int) $r['tenant_id'];
        $cp = $this->db->one('SELECT * FROM counterparties WHERE id = ? AND tenant_id = ?', [(int) $r['counterparty_id'], $tenantId]);
        if ($cp === null) {
            throw new NotFoundException();
        }
        $sensitive = $p->can('sensitive.access');
        $data = [
            'erstellt_am' => gmdate('c'),
            'partei' => [
                'name' => $cp['name'], 'typ' => $cp['party_type'], 'rechtsform' => $cp['legal_form'], 'register_nr' => $cp['registration_id'],
                'email' => $cp['contact_email'], 'adresse' => trim(($cp['address_line'] ?? '') . ', ' . ($cp['postal_code'] ?? '') . ' ' . ($cp['city'] ?? '') . ' ' . ($cp['country'] ?? '')),
                'besonders_schuetzenswert_markiert' => (bool) $cp['sensitive_data'],
            ],
            'vertraege' => $this->db->all('SELECT c.contract_number AS nummer, c.title AS titel, c.status, cp.role_label AS rolle FROM contract_parties cp JOIN contracts c ON c.id = cp.contract_id AND c.tenant_id = cp.tenant_id WHERE cp.counterparty_id = ? AND cp.tenant_id = ? ORDER BY c.contract_number', [(int) $cp['id'], $tenantId]),
        ];
        if ((int) $cp['sensitive_data'] === 1 && !$sensitive) {
            $data['partei'] = ['name' => $cp['name'], 'hinweis' => 'Weitere Angaben sind als besonders schützenswert markiert und nur mit Berechtigung einsehbar.'];
        }
        $this->audit->log('privacy.access_report', 'success', $tenantId, $p->userId, 'privacy_request', $requestId, ['sensitive_shown' => $sensitive]);
        return $data;
    }

    /** Hinderungsgründe für eine Löschung/Anonymisierung der Partei. @return list<string> */
    public function erasureBlockers(Principal $p, int $requestId): array
    {
        $r = $this->request($p, $requestId);
        if ($r['counterparty_id'] === null) {
            return ['Keine Vertragspartei zugeordnet.'];
        }
        return $this->blockers((int) $r['tenant_id'], (int) $r['counterparty_id']);
    }

    /**
     * Entscheidung festhalten. Bei "granted" für eine Löschanfrage wird die Partei anonymisiert – aber nur,
     * wenn keine Hinderungsgründe bestehen (sonst "partially_granted" oder "refused" mit Begründung).
     */
    public function decide(Principal $p, int $requestId, string $status, string $reason): void
    {
        $r = $this->request($p, $requestId);
        if (!in_array($status, ['granted', 'partially_granted', 'refused'], true)) {
            throw new ValidationException(['status' => 'Ungültiger Status.']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Eine Begründung ist zwingend.']);
        }
        if ($r['status'] !== 'open') {
            throw new BusinessRuleException('Die Anfrage wurde bereits entschieden.');
        }
        $tenantId = (int) $r['tenant_id'];
        $this->db->transaction(function () use ($r, $p, $status, $reason, $tenantId): void {
            if ($r['request_type'] === 'erasure' && $status === 'granted') {
                if ($r['counterparty_id'] === null) {
                    throw new BusinessRuleException('Ohne zugeordnete Vertragspartei kann nicht gelöscht werden.');
                }
                $b = $this->blockers($tenantId, (int) $r['counterparty_id']);
                if ($b !== []) {
                    throw new BusinessRuleException('Vollständige Löschung nicht möglich: ' . implode(' ', $b) . ' Bitte „teilweise“ oder „abgelehnt“ wählen.');
                }
                $this->anonymize($tenantId, (int) $r['counterparty_id'], full: true);
            } elseif ($r['request_type'] === 'erasure' && $status === 'partially_granted' && $r['counterparty_id'] !== null) {
                if ($this->holds->counterpartyHeld($tenantId, (int) $r['counterparty_id'])) {
                    throw new BusinessRuleException('Wegen einer rechtlichen Sperre ist keine Änderung der Daten zulässig.');
                }
                $this->anonymize($tenantId, (int) $r['counterparty_id'], full: false);
            }
            $this->db->run('UPDATE deletion_requests SET status = ?, decision_reason = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = \'open\'', [$status, trim($reason), $p->userId, (int) $r['id']]);
            $this->audit->log('privacy.request_decided', 'success', $tenantId, $p->userId, 'privacy_request', (int) $r['id'], ['status' => $status, 'type' => $r['request_type']]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p): array
    {
        $this->ac->require($p, 'privacy.manage');
        return $this->db->all('SELECT id, request_type, subject_description, status, created_at, decided_at, DATE_ADD(created_at, INTERVAL 30 DAY) AS due_hint FROM deletion_requests WHERE tenant_id = ? ORDER BY id DESC', [$this->tenant($p)]);
    }

    // ------------------------------------------------------------------ intern

    /** @return list<string> */
    private function blockers(int $tenantId, int $counterpartyId): array
    {
        $b = [];
        if ($this->holds->counterpartyHeld($tenantId, $counterpartyId)) {
            $b[] = 'Rechtliche Sperre aktiv.';
        }
        foreach ($this->db->all('SELECT c.id, c.contract_number FROM contract_parties cp JOIN contracts c ON c.id = cp.contract_id AND c.tenant_id = cp.tenant_id WHERE cp.counterparty_id = ? AND cp.tenant_id = ?', [$counterpartyId, $tenantId]) as $c) {
            $e = $this->retention->evaluate($tenantId, (int) $c['id']);
            $hasRetentionBlock = false;
            foreach ($e['blocked_by'] as $reason) {
                if (str_contains($reason, 'nicht archiviert') || str_contains($reason, 'läuft bis') || str_contains($reason, 'Keine Aufbewahrungsregel') || str_contains($reason, 'Startereignis')) {
                    $hasRetentionBlock = true;
                }
            }
            if ($hasRetentionBlock) {
                $b[] = 'Vertrag ' . $c['contract_number'] . ' unterliegt der Aufbewahrung bzw. ist nicht abgeschlossen.';
            }
        }
        return $b;
    }

    private function anonymize(int $tenantId, int $counterpartyId, bool $full): void
    {
        if ($full) {
            $this->db->run(
                "UPDATE counterparties SET name = CONCAT('[gelöscht #', id, ']'), legal_form = NULL, registration_id = NULL, contact_email = NULL, address_line = NULL, postal_code = NULL, city = NULL, country = NULL, linked_user_id = NULL, archived_at = COALESCE(archived_at, UTC_TIMESTAMP(6)) WHERE id = ? AND tenant_id = ?",
                [$counterpartyId, $tenantId],
            );
        } else {
            // Einschränkung: Kontaktdaten entfernen, Identifikation für bestehende Verträge bleibt.
            $this->db->run('UPDATE counterparties SET contact_email = NULL, address_line = NULL, postal_code = NULL, linked_user_id = NULL, archived_at = COALESCE(archived_at, UTC_TIMESTAMP(6)) WHERE id = ? AND tenant_id = ?', [$counterpartyId, $tenantId]);
        }
    }

    /** @return array<string,mixed> */
    private function request(Principal $p, int $id): array
    {
        $this->ac->require($p, 'privacy.manage');
        $r = $this->db->one('SELECT * FROM deletion_requests WHERE id = ? AND tenant_id = ?', [$id, $this->tenant($p)]);
        if ($r === null) {
            throw new NotFoundException();
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
