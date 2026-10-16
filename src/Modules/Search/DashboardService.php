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
}
