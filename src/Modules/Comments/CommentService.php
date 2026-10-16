<?php

declare(strict_types=1);

namespace App\Modules\Comments;

use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

/**
 * Kommentare. Sichtbarkeit:
 *  - intern ("internal"): nur interne Benutzer mit comment.internal
 *  - geteilt ("shared"): intern und Vertragspartner mit Mandat "comment"
 *  - Prüfvermerke ("audit_note"): nur Revision (eigene) und interne Benutzer mit audit.view – NIE Vertragspartner
 */
final class CommentService
{
    public function __construct(private readonly Database $db, private readonly AccessControl $ac, private readonly AuditLogger $audit)
    {
    }

    public function add(Principal $p, int $contractId, string $body, string $visibility = 'internal'): int
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new ValidationException(['body' => 'Kommentar: 1–4000 Zeichen.']);
        }
        if (!in_array($visibility, ['internal', 'shared'], true)) {
            throw new ValidationException(['visibility' => 'Ungültige Sichtbarkeit.']);
        }
        if ($p->isExternal()) {
            if ($p->userType !== 'external_party') {
                throw new ForbiddenException();
            }
            $c = $this->ac->contract($p, $contractId, 'comment');
            $visibility = 'shared';
        } else {
            $c = $this->ac->contract($p, $contractId, 'comment');
            if ($visibility === 'internal' && !$p->can('comment.internal')) {
                throw new ForbiddenException();
            }
        }
        $this->db->run("INSERT INTO contract_comments (tenant_id, contract_id, author_user_id, visibility, kind, body) VALUES (?,?,?,?, 'comment', ?)", [(int) $c['tenant_id'], $contractId, $p->userId, $visibility, $body]);
        $id = $this->db->lastId();
        $this->audit->log('comment.added', 'success', (int) $c['tenant_id'], $p->userId, 'comment', $id, ['contract_id' => $contractId, 'visibility' => $visibility]);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        $tid = (int) $c['tenant_id'];
        if ($p->isExternal()) {
            if ($p->userType === 'external_party') {
                return $this->db->all("SELECT cc.id, cc.body, cc.created_at, u.display_name AS author, cc.kind FROM contract_comments cc JOIN users u ON u.id = cc.author_user_id WHERE cc.contract_id = ? AND cc.tenant_id = ? AND cc.kind = 'comment' AND cc.visibility = 'shared' ORDER BY cc.id", [$contractId, $tid]);
            }
            if ($p->userType === 'auditor') {
                return $this->db->all("SELECT cc.id, cc.body, cc.created_at, u.display_name AS author, cc.kind FROM contract_comments cc JOIN users u ON u.id = cc.author_user_id WHERE cc.contract_id = ? AND cc.tenant_id = ? AND cc.kind = 'audit_note' AND cc.author_user_id = ? ORDER BY cc.id", [$contractId, $tid, $p->userId]);
            }
            return [];
        }
        $scopes = $this->ac->visibleCommentScopes($p);
        $in = implode(',', array_fill(0, count($scopes), '?'));
        $kinds = $p->can('audit.view') ? "('comment','audit_note')" : "('comment')";
        return $this->db->all("SELECT cc.id, cc.body, cc.created_at, u.display_name AS author, cc.kind, cc.visibility FROM contract_comments cc JOIN users u ON u.id = cc.author_user_id WHERE cc.contract_id = ? AND cc.tenant_id = ? AND cc.visibility IN ({$in}) AND cc.kind IN {$kinds} ORDER BY cc.id", [$contractId, $tid, ...$scopes]);
    }
}
