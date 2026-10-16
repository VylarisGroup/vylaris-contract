<?php

declare(strict_types=1);

namespace App\Modules\Deadlines;

use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/** Interne Aufgaben zu einem Vertrag (nur interne Benutzer). */
final class TaskService
{
    public function __construct(private readonly Database $db, private readonly AccessControl $ac, private readonly AuditLogger $audit)
    {
    }

    public function add(Principal $p, int $contractId, string $title, ?int $assigneeId, ?string $dueDate): int
    {
        $c = $this->ac->contract($p, $contractId, 'deadline');
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ValidationException(['title' => 'Bitte einen Titel angeben.']);
        }
        if ($dueDate !== null && $dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
            throw new ValidationException(['due_date' => 'Datum im Format JJJJ-MM-TT.']);
        }
        if ($assigneeId !== null && !$this->db->value("SELECT 1 FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [(int) $c['tenant_id'], $assigneeId])) {
            throw new ValidationException(['assignee_user_id' => 'Die Person gehört nicht zum Mandanten.']);
        }
        $this->db->run('INSERT INTO contract_tasks (tenant_id, contract_id, title, assignee_user_id, due_date) VALUES (?,?,?,?,?)', [(int) $c['tenant_id'], $contractId, $title, $assigneeId, $dueDate !== '' ? $dueDate : null]);
        $id = $this->db->lastId();
        $this->audit->log('task.added', 'success', (int) $c['tenant_id'], $p->userId, 'task', $id, ['contract_id' => $contractId]);
        return $id;
    }

    public function complete(Principal $p, int $taskId): void
    {
        $t = $this->db->one('SELECT * FROM contract_tasks WHERE id = ?', [$taskId]);
        if ($t === null) {
            throw new NotFoundException();
        }
        $this->ac->contract($p, (int) $t['contract_id'], 'view');
        if ((int) $t['assignee_user_id'] !== $p->userId) {
            $this->ac->contract($p, (int) $t['contract_id'], 'deadline'); // Fremde Aufgaben nur mit Bearbeitungsrecht
        }
        $this->db->run("UPDATE contract_tasks SET status = 'done' WHERE id = ?", [$taskId]);
        $this->audit->log('task.completed', 'success', (int) $t['tenant_id'], $p->userId, 'task', $taskId);
    }

    /** @return list<array<string,mixed>> */
    public function forContract(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        if ($p->isExternal()) {
            return [];
        }
        return $this->db->all('SELECT id, title, assignee_user_id, due_date, status FROM contract_tasks WHERE contract_id = ? AND tenant_id = ? ORDER BY status, due_date', [$contractId, (int) $c['tenant_id']]);
    }
}
