<?php

declare(strict_types=1);

namespace App\Modules\Deadlines;

use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\WorkflowService;
use App\Modules\Notifications\NotificationService;
use App\Services\AccessControl;

/**
 * Fristen: abgeleitete (Vertragsende, Kündigung, automatische Verlängerung) und manuelle Fristen,
 * Erinnerungen ohne Duplikate, automatischer Statuswechsel "abgelaufen". Berechnungen sind
 * nachvollziehbar in calc_rule dokumentiert. Fristen ersetzen keine Rechtsberatung: Fristberechnung
 * folgt der Vertragsangabe (Tage vor Enddatum), Feiertags-/Zugangsregeln sind nicht abgebildet.
 */
final class DeadlineService
{
    private const KINDS_MANUAL = ['payment', 'performance', 'custom'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly NotificationService $notifications,
        private readonly WorkflowService $workflow,
    ) {
    }

    /**
     * @param list<int> $remindDaysBefore
     */
    public function add(Principal $p, int $contractId, string $kind, string $dueDate, string $label, array $remindDaysBefore = [14, 3], ?int $responsibleUserId = null): int
    {
        $c = $this->ac->contract($p, $contractId, 'deadline');
        if (!in_array($kind, self::KINDS_MANUAL, true)) {
            throw new ValidationException(['kind' => 'Ungültige Fristart.']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1 || !checkdate((int) substr($dueDate, 5, 2), (int) substr($dueDate, 8, 2), (int) substr($dueDate, 0, 4))) {
            throw new ValidationException(['due_date' => 'Datum im Format JJJJ-MM-TT erforderlich.']);
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 255) {
            throw new ValidationException(['label' => 'Bitte eine Bezeichnung angeben.']);
        }
        $days = $this->normalizeDays($remindDaysBefore);
        if ($responsibleUserId !== null) {
            $m = $this->db->value("SELECT 1 FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [(int) $c['tenant_id'], $responsibleUserId]);
            if (!$m) {
                throw new ValidationException(['responsible_user_id' => 'Verantwortliche Person gehört nicht zum Mandanten.']);
            }
        }
        $this->db->run(
            'INSERT INTO contract_deadlines (tenant_id, contract_id, kind, due_date, label, calc_rule, remind_days_before, responsible_user_id) VALUES (?,?,?,?,?,?,?,?)',
            [(int) $c['tenant_id'], $contractId, $kind, $dueDate, $label, 'manuell', json_encode($days), $responsibleUserId],
        );
        $id = $this->db->lastId();
        $this->audit->log('deadline.added', 'success', (int) $c['tenant_id'], $p->userId, 'deadline', $id, ['contract_id' => $contractId]);
        return $id;
    }

    public function setStatus(Principal $p, int $deadlineId, string $status): void
    {
        if (!in_array($status, ['done', 'cancelled', 'open'], true)) {
            throw new ValidationException(['status' => 'Ungültiger Status.']);
        }
        $d = $this->db->one('SELECT * FROM contract_deadlines WHERE id = ?', [$deadlineId]);
        if ($d === null) {
            throw new NotFoundException();
        }
        $this->ac->contract($p, (int) $d['contract_id'], 'deadline'); // wirft NotFound bei fremdem Mandanten
        $this->db->run('UPDATE contract_deadlines SET status = ? WHERE id = ?', [$status, $deadlineId]);
        $this->audit->log('deadline.' . $status, 'success', (int) $d['tenant_id'], $p->userId, 'deadline', $deadlineId);
    }

    /** @return list<array<string,mixed>> */
    public function forContract(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        if ($p->isExternal()) {
            return [];
        }
        return $this->db->all('SELECT id, kind, due_date, label, calc_rule, status, remind_days_before, responsible_user_id FROM contract_deadlines WHERE contract_id = ? AND tenant_id = ? ORDER BY due_date', [$contractId, (int) $c['tenant_id']]);
    }

    /** Leitet Fristen aus den Vertragsdaten ab (idempotent, aktualisiert nur offene abgeleitete Fristen). */
    public function syncDerived(int $contractId): int
    {
        $c = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        if ($c === null || !in_array($c['status'], ['active', 'signature', 'approved'], true) || $c['end_date'] === null) {
            return 0;
        }
        $end = new \DateTimeImmutable((string) $c['end_date']);
        $want = [
            'contract_end' => [$end, 'auto:Vertragsende', 'Vertragsende', [30, 7]],
        ];
        if ($c['notice_period_days'] !== null && (int) $c['notice_period_days'] > 0) {
            $want['notice'] = [$end->modify('-' . (int) $c['notice_period_days'] . ' days'), 'auto:Enddatum minus ' . (int) $c['notice_period_days'] . ' Tage Kündigungsfrist', 'Kündigungsfrist (letzter Kündigungstermin)', [90, 30, 7]];
        }
        if ((int) $c['auto_renew'] === 1) {
            $want['auto_renewal'] = [$end, 'auto:Automatische Verlängerung zum Enddatum', 'Automatische Verlängerung', [60, 14]];
        }
        $changed = 0;
        foreach ($want as $kind => [$date, $rule, $label, $days]) {
            $row = $this->db->one("SELECT id, due_date, status FROM contract_deadlines WHERE contract_id = ? AND kind = ? AND calc_rule LIKE 'auto:%' ORDER BY id DESC LIMIT 1", [$contractId, $kind]);
            if ($row === null) {
                $this->db->run('INSERT INTO contract_deadlines (tenant_id, contract_id, kind, due_date, label, calc_rule, remind_days_before, responsible_user_id) VALUES (?,?,?,?,?,?,?,?)', [(int) $c['tenant_id'], $contractId, $kind, $date->format('Y-m-d'), $label, $rule, json_encode($days), (int) $c['owner_user_id']]);
                $changed++;
            } elseif ($row['status'] === 'open' && $row['due_date'] !== $date->format('Y-m-d')) {
                $this->db->run('UPDATE contract_deadlines SET due_date = ?, calc_rule = ? WHERE id = ?', [$date->format('Y-m-d'), $rule, (int) $row['id']]);
                $changed++;
            }
        }
        // Abgeleitete Fristen, die nicht mehr gelten (z. B. Kündigungsfrist entfernt), schliessen.
        foreach (['notice', 'auto_renewal'] as $kind) {
            if (!isset($want[$kind])) {
                $changed += $this->db->execute("UPDATE contract_deadlines SET status = 'cancelled' WHERE contract_id = ? AND kind = ? AND calc_rule LIKE 'auto:%' AND status = 'open'", [$contractId, $kind]);
            }
        }
        return $changed;
    }

    /** @return array{contracts:int,changed:int} */
    public function syncAllDerived(): array
    {
        $ids = $this->db->all("SELECT id FROM contracts WHERE status IN ('active','signature','approved') AND end_date IS NOT NULL");
        $changed = 0;
        foreach ($ids as $r) {
            $changed += $this->syncDerived((int) $r['id']);
        }
        return ['contracts' => count($ids), 'changed' => $changed];
    }

    /**
     * Erzeugt fällige Erinnerungen (je Frist und Erinnerungstag genau einmal) sowie "überfällig"-Hinweise.
     * Empfänger: verantwortliche Person, sonst Vertragsverantwortliche. Nur wer den Vertrag sehen darf.
     *
     * @return array{created:int,skipped:int}
     */
    public function scanAndNotify(): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $rows = $this->db->all(
            "SELECT d.*, c.contract_number, c.owner_user_id FROM contract_deadlines d JOIN contracts c ON c.id = d.contract_id AND c.tenant_id = d.tenant_id
              WHERE d.status = 'open' AND c.status NOT IN ('archived','cancelled','rejected','withdrawn') AND d.due_date <= ?",
            [$today->modify('+400 days')->format('Y-m-d')],
        );
        $created = 0;
        $skipped = 0;
        foreach ($rows as $d) {
            $due = new \DateTimeImmutable((string) $d['due_date']);
            $daysLeft = (int) $today->diff($due)->format('%r%a');
            $remind = json_decode((string) $d['remind_days_before'], true) ?: [];
            $recipient = (int) ($d['responsible_user_id'] ?? $d['owner_user_id']);
            if (!$this->mayReceive($recipient, (int) $d['tenant_id'], (int) $d['contract_id'])) {
                $skipped++;
                continue;
            }
            $link = '/vertraege/' . (int) $d['contract_id'];
            $dateFmt = $due->format('d.m.Y');
            if ($daysLeft < 0) {
                $key = 'deadline:' . $d['id'] . ':overdue';
                $subject = 'Frist überfällig: ' . $d['label'] . ' (' . $d['contract_number'] . ')';
                $ok = $this->notifications->notify((int) $d['tenant_id'], $recipient, $key, $subject, 'Die Frist war am ' . $dateFmt . ' fällig und ist noch offen.', $link);
                $created += $ok ? 1 : 0;
                continue;
            }
            // Das kleinste bereits erreichte Erinnerungsfenster auslösen (verpasste Läufe holen nach, aber nur einmal pro Stufe).
            $reached = array_values(array_filter(array_map('intval', $remind), static fn (int $r): bool => $daysLeft <= $r));
            if ($reached === []) {
                continue;
            }
            $r = min($reached);
            $key = 'deadline:' . $d['id'] . ':r' . $r;
            $subject = 'Frist in ' . $daysLeft . ' Tag' . ($daysLeft === 1 ? '' : 'en') . ': ' . $d['label'] . ' (' . $d['contract_number'] . ')';
            $ok = $this->notifications->notify((int) $d['tenant_id'], $recipient, $key, $subject, 'Fälligkeit: ' . $dateFmt . '.', $link);
            $created += $ok ? 1 : 0;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Setzt aktive Verträge ohne automatische Verlängerung nach Ablauf des Enddatums auf "abgelaufen". */
    public function expireContracts(): int
    {
        $today = $this->clock->now()->format('Y-m-d');
        $rows = $this->db->all("SELECT id FROM contracts WHERE status = 'active' AND end_date IS NOT NULL AND end_date < ? AND auto_renew = 0", [$today]);
        $n = 0;
        foreach ($rows as $r) {
            try {
                $this->workflow->move((int) $r['id'], 'expired', null, 'Vertragsende erreicht');
                $n++;
            } catch (DomainException) {
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ intern

    private function mayReceive(int $userId, int $tenantId, int $contractId): bool
    {
        $p = $this->ac->principal($userId, $tenantId);
        if ($p === null) {
            return false;
        }
        [$vis, $vp] = $this->ac->visibleContractsSql($p, 'c');
        return (bool) $this->db->value("SELECT 1 FROM contracts c WHERE c.id = ? AND ({$vis})", [$contractId, ...$vp]);
    }

    /** @param list<int|string> $days @return list<int> */
    private function normalizeDays(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            if (!is_numeric($d) || (int) $d < 0 || (int) $d > 730) {
                throw new ValidationException(['remind_days_before' => 'Erinnerungstage müssen zwischen 0 und 730 liegen.']);
            }
            $out[] = (int) $d;
        }
        $out = array_values(array_unique($out));
        rsort($out);
        return array_slice($out, 0, 8);
    }
}
