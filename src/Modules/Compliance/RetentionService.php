<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Core\Clock;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/**
 * Aufbewahrungsregeln. Es gibt bewusst KEINE Standarddauer: Jeder Mandant legt Regeln pro Zweck, Vertragstyp
 * und Rechtsgebiet mit Rechtsgrundlage selbst fest. Neue Regeln gelten als "zu prüfen" (to_review) – auf Basis einer
 * ungeprüften Regel wird nie automatisch gelöscht. Die Plattform ersetzt keine rechtliche Beratung.
 */
final class RetentionService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly LegalHoldService $holds,
    ) {
    }

    public function save(Principal $p, array $in, ?int $id = null): int
    {
        $this->ac->require($p, 'retention.manage');
        $tenantId = $this->tenant($p);
        $name = trim((string) ($in['name'] ?? ''));
        $purpose = trim((string) ($in['purpose'] ?? ''));
        $basis = trim((string) ($in['legal_basis'] ?? ''));
        $years = (int) ($in['retention_years'] ?? 0);
        $jur = strtoupper(trim((string) ($in['jurisdiction'] ?? '')));
        $start = (string) ($in['start_event'] ?? 'contract_end');
        $onExpiry = (string) ($in['on_expiry'] ?? 'review');
        $typeId = ($in['contract_type_id'] ?? '') === '' || ($in['contract_type_id'] ?? null) === null ? null : (int) $in['contract_type_id'];
        $err = [];
        if ($name === '' || mb_strlen($name) > 190) {
            $err['name'] = 'Name erforderlich.';
        }
        if ($purpose === '' || mb_strlen($purpose) > 255) {
            $err['purpose'] = 'Zweck erforderlich (Datenminimierung: wozu wird aufbewahrt?).';
        }
        if ($basis === '' || mb_strlen($basis) > 500) {
            $err['legal_basis'] = 'Rechtsgrundlage erforderlich.';
        }
        if ($years < 1 || $years > 100) {
            $err['retention_years'] = 'Aufbewahrungsdauer 1–100 Jahre.';
        }
        if (preg_match('/^[A-Z]{2}(-[A-Z]{2,3})?$|^EU$/', $jur) !== 1) {
            $err['jurisdiction'] = 'Rechtsgebiet (z. B. CH, DE, EU).';
        }
        if (!in_array($start, ['contract_end', 'archived_at', 'signed_at'], true)) {
            $err['start_event'] = 'Ungültiges Startereignis.';
        }
        if (!in_array($onExpiry, ['review', 'delete'], true)) {
            $err['on_expiry'] = 'Ungültige Aktion.';
        }
        if ($typeId !== null && !$this->db->value('SELECT 1 FROM contract_types WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)', [$typeId, $tenantId])) {
            $err['contract_type_id'] = 'Ungültiger Vertragstyp.';
        }
        if ($err !== []) {
            throw new ValidationException($err);
        }
        if ($id === null) {
            $this->db->run('INSERT INTO retention_policies (tenant_id, name, contract_type_id, jurisdiction, purpose, legal_basis, retention_years, start_event, on_expiry) VALUES (?,?,?,?,?,?,?,?,?)', [$tenantId, $name, $typeId, $jur, $purpose, $basis, $years, $start, $onExpiry]);
            $id = $this->db->lastId();
            $this->audit->log('retention.policy_created', 'success', $tenantId, $p->userId, 'retention_policy', $id);
            return $id;
        }
        if (!$this->db->value('SELECT 1 FROM retention_policies WHERE id = ? AND tenant_id = ?', [$id, $tenantId])) {
            throw new NotFoundException();
        }
        // Jede Änderung setzt die rechtliche Prüfung zurück.
        $this->db->run("UPDATE retention_policies SET name=?, contract_type_id=?, jurisdiction=?, purpose=?, legal_basis=?, retention_years=?, start_event=?, on_expiry=?, legal_review_status='to_review' WHERE id=? AND tenant_id=?", [$name, $typeId, $jur, $purpose, $basis, $years, $start, $onExpiry, $id, $tenantId]);
        $this->audit->log('retention.policy_updated', 'success', $tenantId, $p->userId, 'retention_policy', $id);
        return $id;
    }

    /** Bestätigt, dass die Regel rechtlich geprüft wurde (Verantwortung liegt beim Mandanten). */
    public function markReviewed(Principal $p, int $id): void
    {
        $this->ac->require($p, 'retention.manage');
        $n = $this->db->execute("UPDATE retention_policies SET legal_review_status = 'reviewed' WHERE id = ? AND tenant_id = ?", [$id, $this->tenant($p)]);
        if ($n === 0 && !$this->db->value('SELECT 1 FROM retention_policies WHERE id = ? AND tenant_id = ?', [$id, $this->tenant($p)])) {
            throw new NotFoundException();
        }
        $this->audit->log('retention.policy_reviewed', 'success', $this->tenant($p), $p->userId, 'retention_policy', $id);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p): array
    {
        $this->ac->require($p, 'retention.manage');
        return $this->db->all('SELECT rp.*, ct.name AS type_name FROM retention_policies rp LEFT JOIN contract_types ct ON ct.id = rp.contract_type_id WHERE rp.tenant_id = ? ORDER BY rp.name', [$this->tenant($p)]);
    }

    /**
     * Ermittelt die Regel und das Ende der Aufbewahrung. Spezifischere Regeln (Vertragstyp) gehen vor.
     * @return array{policy:?array<string,mixed>,retain_until:?string,blocked_by:list<string>,deletable:bool}
     */
    public function evaluate(int $tenantId, int $contractId): array
    {
        $c = $this->db->one('SELECT * FROM contracts WHERE id = ? AND tenant_id = ?', [$contractId, $tenantId]);
        if ($c === null) {
            throw new NotFoundException();
        }
        $jur = strtoupper((string) ($this->db->value('SELECT jurisdiction_default FROM tenants WHERE id = ?', [$tenantId]) ?? 'CH'));
        $policy = $this->db->one(
            'SELECT * FROM retention_policies WHERE tenant_id = ? AND (contract_type_id = ? OR contract_type_id IS NULL) AND jurisdiction = ?
              ORDER BY contract_type_id IS NULL ASC, retention_years DESC LIMIT 1',
            [$tenantId, (int) $c['contract_type_id'], $jur],
        );
        $blocked = [];
        if ($c['status'] !== 'archived') {
            $blocked[] = 'Vertrag ist nicht archiviert.';
        }
        if ($policy === null) {
            $blocked[] = 'Keine Aufbewahrungsregel hinterlegt.';
            return ['policy' => null, 'retain_until' => null, 'blocked_by' => $blocked, 'deletable' => false];
        }
        $start = match ($policy['start_event']) {
            'archived_at' => $c['archived_at'] !== null ? substr((string) $c['archived_at'], 0, 10) : null,
            'signed_at' => ($d = $this->db->value("SELECT MIN(created_at) FROM signature_events se JOIN signature_requests sr ON sr.id = se.signature_request_id WHERE sr.contract_id = ? AND se.event_type = 'signed'", [$contractId])) ? substr((string) $d, 0, 10) : null,
            default => $c['end_date'],
        };
        $until = null;
        if ($start === null) {
            $blocked[] = 'Startereignis der Aufbewahrung ist unbekannt (' . $policy['start_event'] . ').';
        } else {
            $until = (new \DateTimeImmutable((string) $start))->modify('+' . (int) $policy['retention_years'] . ' years')->format('Y-m-d');
            if ($until >= $this->clock->now()->format('Y-m-d')) {
                $blocked[] = 'Aufbewahrungsfrist läuft bis ' . $until . '.';
            }
        }
        if ($this->holds->contractHeld($tenantId, $contractId)) {
            $blocked[] = 'Rechtliche Sperre aktiv.';
        }
        if ($policy['legal_review_status'] !== 'reviewed') {
            $blocked[] = 'Aufbewahrungsregel ist rechtlich nicht als geprüft bestätigt.';
        }
        if ($policy['on_expiry'] !== 'delete') {
            $blocked[] = 'Die Regel sieht nach Ablauf eine manuelle Prüfung statt Löschung vor.';
        }
        if ($this->db->value('SELECT 1 FROM contracts WHERE parent_contract_id = ? AND tenant_id = ?', [$contractId, $tenantId])) {
            $blocked[] = 'Es existieren Nachträge/Änderungen, die zuerst bearbeitet werden müssen.';
        }
        return ['policy' => $policy, 'retain_until' => $until, 'blocked_by' => $blocked, 'deletable' => $blocked === []];
    }

    /** Archivierte Verträge, deren Frist abgelaufen ist (zur Prüfung/Löschung). @return list<array<string,mixed>> */
    public function reviewQueue(Principal $p): array
    {
        $this->ac->require($p, 'retention.manage');
        $tenantId = $this->tenant($p);
        $out = [];
        foreach ($this->db->all("SELECT id, contract_number, title FROM contracts WHERE tenant_id = ? AND status = 'archived' ORDER BY id LIMIT 500", [$tenantId]) as $c) {
            $e = $this->evaluate($tenantId, (int) $c['id']);
            if ($e['retain_until'] !== null && $e['retain_until'] < $this->clock->now()->format('Y-m-d')) {
                $out[] = $c + ['retain_until' => $e['retain_until'], 'deletable' => $e['deletable'], 'blocked_by' => $e['blocked_by']];
            }
        }
        return $out;
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }
}
