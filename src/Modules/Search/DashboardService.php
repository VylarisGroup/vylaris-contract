<?php

declare(strict_types=1);

namespace App\Modules\Search;

use App\Core\Clock;
use App\Core\Database;
use App\Models\Principal;
use App\Services\AccessControl;

/** Kennzahlen der Startseite – ausschliesslich über den Sichtbarkeitsfilter berechnet. */
final class DashboardService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly Clock $clock,
        private readonly ?\App\Modules\Audit\AuditLogger $audit = null,
    ) {
    }

    /**
     * @return array{by_status:array<string,int>,expiring:list<array<string,mixed>>,deadlines:list<array<string,mixed>>,approvals:list<array<string,mixed>>,tasks:list<array<string,mixed>>,total:int}
     */
    public function overview(Principal $p): array
    {
        [$vis, $vp] = $this->ac->visibleContractsSql($p, 'c');
        $today = $this->clock->now()->format('Y-m-d');
        $in90 = $this->clock->now()->modify('+90 days')->format('Y-m-d');

        $byStatus = [];
        foreach ($this->db->all("SELECT c.status, COUNT(*) AS n FROM contracts c WHERE {$vis} GROUP BY c.status", $vp) as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }
        $expiring = $this->db->all(
            "SELECT c.id, c.contract_number, c.title, c.end_date FROM contracts c
              WHERE ({$vis}) AND c.status = 'active' AND c.end_date IS NOT NULL AND c.end_date BETWEEN ? AND ? ORDER BY c.end_date LIMIT 10",
            [...$vp, $today, $in90],
        );
        $deadlines = [];
        $approvals = [];
        $tasks = [];
        if (!$p->isExternal()) {
            $deadlines = $this->db->all(
                "SELECT d.id, d.contract_id, d.kind, d.due_date, d.label, c.contract_number, c.title FROM contract_deadlines d JOIN contracts c ON c.id = d.contract_id AND c.tenant_id = d.tenant_id
                  WHERE ({$vis}) AND d.status = 'open' AND d.due_date <= ? ORDER BY d.due_date LIMIT 10",
                [...$vp, $in90],
            );
            $approvals = $this->db->all(
                "SELECT a.id, a.contract_id, c.contract_number, c.title, a.step_no FROM approvals a JOIN contracts c ON c.id = a.contract_id AND c.tenant_id = a.tenant_id
                  WHERE a.approver_user_id = ? AND a.status = 'pending' AND c.tenant_id = ? AND ({$vis}) ORDER BY a.created_at LIMIT 10",
                [$p->userId, $p->tenantId, ...$vp],
            );
            $tasks = $this->db->all(
                "SELECT t.id, t.contract_id, t.title, t.due_date, c.contract_number FROM contract_tasks t JOIN contracts c ON c.id = t.contract_id AND c.tenant_id = t.tenant_id
                  WHERE t.assignee_user_id = ? AND t.status = 'open' AND ({$vis}) ORDER BY t.due_date IS NULL, t.due_date LIMIT 10",
                [$p->userId, ...$vp],
            );
        }
        return ['by_status' => $byStatus, 'expiring' => $expiring, 'deadlines' => $deadlines, 'approvals' => $approvals, 'tasks' => $tasks, 'total' => array_sum($byStatus)];
    }

    /**
     * Auswertungen für die Startseite (nur interne Benutzer; ausschliesslich über den Sichtbarkeitsfilter).
     *
     * @return array{expiry:array{overdue:int,d30:int,d60:int,d90:int},values:list<array<string,mixed>>,approval:array{avg_days:?float,decided:int,pending:int,oldest_pending_days:?int},signatures:array{open:int,oldest_days:?int,list:list<array<string,mixed>>},created:list<array{month:string,n:int}>}
     */
    public function analytics(Principal $p): array
    {
        [$vis, $vp] = $this->ac->visibleContractsSql($p, 'c');
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $in = static fn (int $n): string => $now->modify("+{$n} days")->format('Y-m-d');

        $e = $this->db->one(
            "SELECT COALESCE(SUM(c.end_date < ?),0) AS overdue, COALESCE(SUM(c.end_date >= ? AND c.end_date <= ?),0) AS d30, COALESCE(SUM(c.end_date > ? AND c.end_date <= ?),0) AS d60, COALESCE(SUM(c.end_date > ? AND c.end_date <= ?),0) AS d90
               FROM contracts c WHERE ({$vis}) AND c.status = 'active' AND c.end_date IS NOT NULL",
            [$today, $today, $in(30), $in(30), $in(60), $in(60), $in(90), ...$vp],
        ) ?? [];

        $values = $this->db->all(
            "SELECT ct.name AS type_name, c.value_currency AS currency, COUNT(*) AS n, SUM(c.value_amount) AS total
               FROM contracts c JOIN contract_types ct ON ct.id = c.contract_type_id
              WHERE ({$vis}) AND c.status = 'active' AND c.value_amount IS NOT NULL AND c.value_currency IS NOT NULL
              GROUP BY ct.name, c.value_currency ORDER BY ct.name, c.value_currency LIMIT 100",
            $vp,
        );

        $since = $now->modify('-180 days')->format('Y-m-d H:i:s');
        $ap = $this->db->one(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, a.created_at, a.decided_at)) / 1440 AS avg_days, COUNT(*) AS n
               FROM approvals a JOIN contracts c ON c.id = a.contract_id AND c.tenant_id = a.tenant_id
              WHERE ({$vis}) AND a.status IN ('approved','rejected') AND a.decided_at IS NOT NULL AND a.decided_at >= ?",
            [...$vp, $since],
        ) ?? [];
        $pend = $this->db->one(
            "SELECT COUNT(*) AS n, MIN(a.created_at) AS oldest FROM approvals a JOIN contracts c ON c.id = a.contract_id AND c.tenant_id = a.tenant_id
              WHERE ({$vis}) AND a.status = 'pending'",
            $vp,
        ) ?? [];
        $age = static fn (?string $ts): ?int => $ts === null ? null : max(0, (int) floor(($now->getTimestamp() - (new \DateTimeImmutable($ts, new \DateTimeZone('UTC')))->getTimestamp()) / 86400));

        $sigRows = $this->db->all(
            "SELECT r.id, r.status, r.created_at, c.id AS contract_id, c.contract_number, c.title FROM signature_requests r JOIN contracts c ON c.id = r.contract_id AND c.tenant_id = r.tenant_id
              WHERE ({$vis}) AND r.status IN ('prepared','submitted','partially_signed') ORDER BY r.created_at LIMIT 10",
            $vp,
        );
        foreach ($sigRows as &$sr) {
            $sr['age_days'] = $age((string) $sr['created_at']);
        }
        unset($sr);
        $sigCount = (int) $this->db->value(
            "SELECT COUNT(*) FROM signature_requests r JOIN contracts c ON c.id = r.contract_id AND c.tenant_id = r.tenant_id WHERE ({$vis}) AND r.status IN ('prepared','submitted','partially_signed')",
            $vp,
        );

        $from = $now->modify('first day of this month')->modify('-5 months')->format('Y-m-d 00:00:00');
        $byMonth = [];
        foreach ($this->db->all("SELECT DATE_FORMAT(c.created_at, '%Y-%m') AS m, COUNT(*) AS n FROM contracts c WHERE ({$vis}) AND c.created_at >= ? GROUP BY m", [...$vp, $from]) as $r) {
            $byMonth[(string) $r['m']] = (int) $r['n'];
        }
        $created = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->modify('first day of this month')->modify("-{$i} months")->format('Y-m');
            $created[] = ['month' => $m, 'n' => $byMonth[$m] ?? 0];
        }

        return [
            'expiry' => ['overdue' => (int) ($e['overdue'] ?? 0), 'd30' => (int) ($e['d30'] ?? 0), 'd60' => (int) ($e['d60'] ?? 0), 'd90' => (int) ($e['d90'] ?? 0)],
            'values' => $values,
            'approval' => ['avg_days' => isset($ap['avg_days']) && $ap['avg_days'] !== null ? round((float) $ap['avg_days'], 1) : null, 'decided' => (int) ($ap['n'] ?? 0), 'pending' => (int) ($pend['n'] ?? 0), 'oldest_pending_days' => $age($pend['oldest'] ?? null)],
            'signatures' => ['open' => $sigCount, 'oldest_days' => $sigRows !== [] ? $sigRows[0]['age_days'] : null, 'list' => $sigRows],
            'created' => $created,
        ];
    }

    /** CSV aller Auswertungen (Semikolon, UTF-8 mit BOM, gegen Formel-Injektion geschützt). */
    public function analyticsCsv(Principal $p): string
    {
        $this->ac->require($p, 'contract.export');
        $a = $this->analytics($p);
        $o = fopen('php://temp', 'w+');
        fwrite($o, "\xEF\xBB\xBF");
        $row = static function ($o, array $cols): void {
            fputcsv($o, array_map([SearchService::class, 'csvSafe'], $cols), ';', '"', '');
        };
        $row($o, ['Auswertung', 'Stand', $this->clock->now()->format('Y-m-d H:i') . ' UTC']);
        $row($o, []);
        $row($o, ['Aktive Verträge nach Ablauf', 'Anzahl']);
        foreach (['overdue' => 'Enddatum überschritten (noch aktiv)', 'd30' => 'Ablauf in 30 Tagen', 'd60' => 'Ablauf in 31–60 Tagen', 'd90' => 'Ablauf in 61–90 Tagen'] as $k => $l) {
            $row($o, [$l, $a['expiry'][$k]]);
        }
        $row($o, []);
        $row($o, ['Vertragswerte aktiver Verträge', 'Währung', 'Anzahl', 'Summe']);
        foreach ($a['values'] as $v) {
            $row($o, [$v['type_name'], $v['currency'], $v['n'], number_format((float) $v['total'], 2, '.', '')]);
        }
        $row($o, []);
        $row($o, ['Freigaben (letzte 180 Tage)', 'Entschieden', 'Ø Dauer in Tagen']);
        $row($o, ['', $a['approval']['decided'], $a['approval']['avg_days'] ?? '']);
        $row($o, ['Offene Freigaben', $a['approval']['pending'], 'ältestes in Tagen: ' . ($a['approval']['oldest_pending_days'] ?? '')]);
        $row($o, []);
        $row($o, ['Offene Signaturen', $a['signatures']['open']]);
        foreach ($a['signatures']['list'] as $s) {
            $row($o, [$s['contract_number'] . ' ' . $s['title'], $s['status'], $s['age_days'] . ' Tage']);
        }
        $row($o, []);
        $row($o, ['Neu erfasste Verträge', 'Anzahl']);
        foreach ($a['created'] as $c) {
            $row($o, [$c['month'], $c['n']]);
        }
        rewind($o);
        $csv = (string) stream_get_contents($o);
        fclose($o);
        $this->audit?->log('export.dashboard_csv', 'success', $p->tenantId, $p->userId, 'export', null, []);
        return $csv;
    }
}
