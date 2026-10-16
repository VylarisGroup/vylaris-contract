<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;

/**
 * Zentrale Autorisierung. JEDER Zugriff auf Verträge und Dokumente läuft hier durch.
 *
 * Grundsätze:
 *  - Objekte werden immer zusammen mit dem Mandanten geladen (WHERE id = ? AND tenant_id = ?).
 *    Fremde Mandanten und nicht existierende IDs sind für den Aufrufer nicht unterscheidbar (404).
 *  - Externe Benutzer erhalten Zugriff ausschliesslich über gültige Mandate
 *    (aktiv, nicht widerrufen, im Zeitfenster, Vertrag zugewiesen, Aktion erlaubt, Vertrag freigegeben).
 *  - Verweigerte Zugriffe werden im Audit-Protokoll festgehalten.
 */
final class AccessControl
{
    /** Aktion -> erforderliche interne Berechtigung */
    private const INTERNAL_ACTION_PERMISSION = [
        'view' => 'contract.view',
        'edit' => 'contract.edit',
        'archive' => 'contract.archive',
        'download' => 'document.download',
        'export' => 'contract.export',
        'approve' => 'approval.decide',
        'request_approval' => 'approval.request',
        'request_signature' => 'signature.request',
        'notary' => 'notary.manage',
        'deadline' => 'deadline.manage',
        'comment' => 'comment.internal',
    ];

    /** Aktion -> Aktionsname im Mandat (allowed_actions) je Mandatstyp */
    private const MANDATE_ACTION = [
        'counterparty' => ['view' => 'read', 'download' => 'download', 'sign' => 'sign', 'comment' => 'comment'],
        'audit' => ['view' => 'read_versions', 'history' => 'read_history', 'evidence' => 'read_evidence', 'download' => 'download_attachments', 'annotate' => 'annotate', 'export_report' => 'export_report'],
        'notary' => ['view' => 'read', 'download' => 'read', 'update_case' => 'update_case', 'upload_return' => 'upload_return'],
    ];

    private const TYPE_FOR_MANDATE = ['counterparty' => 'external_party', 'audit' => 'auditor', 'notary' => 'notary'];

    /** Vertragsstatus, ab denen externe Mandate Einsicht erhalten (freigegebene Stände). */
    private const EXTERNAL_VISIBLE_STATUS = ['approved', 'signature', 'active', 'archived', 'expired'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---------------------------------------------------------------- Principal

    public function principal(int $userId, ?int $tenantId, bool $mfaVerified = false): ?Principal
    {
        $user = $this->db->one('SELECT id, display_name, user_type, status, is_system_admin FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }
        $isInternal = $user['user_type'] === 'internal';

        if ($isInternal) {
            if ($tenantId === null) {
                // Systemadministration ohne Mandantenkontext ist erlaubt.
                if ((int) $user['is_system_admin'] !== 1) {
                    return null;
                }
                return new Principal($userId, 'internal', true, null, ['system_admin'], $this->permissionsForRoles(['system_admin']), [], $mfaVerified, (string) $user['display_name']);
            }
            $tenant = $this->db->one("SELECT id FROM tenants WHERE id = ? AND status = 'active'", [$tenantId]);
            if ($tenant === null) {
                return null;
            }
            $roles = $this->db->all(
                "SELECT r.code FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id
                 WHERE tm.user_id = ? AND tm.tenant_id = ? AND tm.status = 'active'",
                [$userId, $tenantId],
            );
            if ($roles === []) {
                return null; // kein aktives Mitglied dieses Mandanten
            }
            $codes = array_column($roles, 'code');
            $depts = array_map('intval', array_column($this->db->all(
                'SELECT department_id FROM department_memberships WHERE user_id = ? AND tenant_id = ?',
                [$userId, $tenantId],
            ), 'department_id'));
            return new Principal($userId, 'internal', (int) $user['is_system_admin'] === 1, $tenantId, $codes, $this->permissionsForRoles($codes), $depts, $mfaVerified, (string) $user['display_name']);
        }

        // Externe: Rollen aus Mitgliedschaften (beliebiger Mandant), Datenzugriff nur über Mandate.
        $roles = $this->db->all(
            "SELECT DISTINCT r.code FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id
             WHERE tm.user_id = ? AND tm.status = 'active' AND r.is_external = 1",
            [$userId],
        );
        $codes = array_column($roles, 'code');
        return new Principal($userId, (string) $user['user_type'], false, null, $codes, $this->permissionsForRoles($codes), [], $mfaVerified, (string) $user['display_name']);
    }

    /** @param list<string> $roleCodes @return list<string> */
    public function permissionsForRoles(array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($roleCodes), '?'));
        $rows = $this->db->all(
            "SELECT DISTINCT p.code FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code IN ({$in})",
            array_values($roleCodes),
        );
        return array_column($rows, 'code');
    }

    // ---------------------------------------------------------------- Berechtigungen

    public function require(Principal $p, string $permission): void
    {
        if (!$p->can($permission)) {
            $this->deny($p, 'permission', null, $permission);
            throw new ForbiddenException();
        }
    }

    // ---------------------------------------------------------------- Verträge

    /**
     * Liefert den Vertrag oder wirft NotFoundException/ForbiddenException.
     * @return array<string,mixed>
     */
    public function contract(Principal $p, int $contractId, string $action = 'view'): array
    {
        return $p->isExternal()
            ? $this->externalContract($p, $contractId, $action)
            : $this->internalContract($p, $contractId, $action);
    }

    /** @return array<string,mixed> */
    /**
     * Prüft eine Aktion ohne Audit-Eintrag und ohne Exception (nur zur Steuerung der Anzeige, z. B. Buttons).
     * Massgeblich bleibt immer die Prüfung im jeweiligen Service.
     *
     * @param array<string,mixed> $contract bereits geladener, sichtbarer Vertrag
     */
    public function mayDo(Principal $p, array $contract, string $action): bool
    {
        if ($p->isExternal() || $p->tenantId === null || (int) $contract['tenant_id'] !== $p->tenantId) {
            return false;
        }
        $perm = self::INTERNAL_ACTION_PERMISSION[$action] ?? null;
        return $perm !== null && $p->can($perm) && $this->internalScopeAllows($p, $contract, $action);
    }

    private function internalContract(Principal $p, int $contractId, string $action): array
    {
        if ($p->tenantId === null) {
            $this->deny($p, 'contract', $contractId, 'no_tenant_context');
            throw new NotFoundException();
        }
        $c = $this->db->one('SELECT * FROM contracts WHERE id = ? AND tenant_id = ?', [$contractId, $p->tenantId]);
        if ($c === null) {
            $this->deny($p, 'contract', $contractId, 'not_found_or_foreign_tenant');
            throw new NotFoundException();
        }
        $perm = self::INTERNAL_ACTION_PERMISSION[$action] ?? null;
        if ($perm === null || !$p->can($perm)) {
            $this->deny($p, 'contract', $contractId, 'missing_permission:' . $action);
            throw new ForbiddenException();
        }
        if (!$this->internalScopeAllows($p, $c, $action)) {
            // Innerhalb des Mandanten: Existenz nicht preisgeben, wenn nicht im eigenen Geltungsbereich.
            $this->deny($p, 'contract', $contractId, 'out_of_scope');
            throw new NotFoundException();
        }
        return $c;
    }

    /** @param array<string,mixed> $c */
    private function internalScopeAllows(Principal $p, array $c, string $action): bool
    {
        if ($p->can('tenant.manage')) {
            return true;
        }
        $contractId = (int) $c['id'];
        $needsWrite = in_array($action, ['edit', 'archive', 'request_approval', 'request_signature', 'notary', 'deadline'], true);

        if ((int) $c['owner_user_id'] === $p->userId) {
            return true;
        }
        $acl = $this->db->one('SELECT can_edit FROM contract_acl WHERE contract_id = ? AND user_id = ?', [$contractId, $p->userId]);
        if ($acl !== null && (!$needsWrite || (int) $acl['can_edit'] === 1)) {
            return true;
        }
        if ($action === 'approve' || $action === 'view' || $action === 'download' || $action === 'comment') {
            $pending = $this->db->value(
                'SELECT COUNT(*) FROM approvals WHERE contract_id = ? AND approver_user_id = ?',
                [$contractId, $p->userId],
            );
            if ((int) $pending > 0) {
                return true;
            }
        }
        if ($c['department_id'] !== null) {
            return in_array((int) $c['department_id'], $p->departmentIds, true);
        }
        // Mandantenweiter Vertrag (ohne Abteilung): Verwaltung und Genehmigungsstelle, nicht reine Leser.
        return $p->hasRole('contract_manager') || $p->hasRole('approver');
    }

    /** @return array<string,mixed> */
    private function externalContract(Principal $p, int $contractId, string $action): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $rows = $this->db->all(
            "SELECT c.*, m.id AS mandate_id, m.mandate_type, m.allowed_actions
               FROM contracts c
               JOIN external_mandate_contracts mc ON mc.contract_id = c.id
               JOIN external_mandates m ON m.id = mc.mandate_id AND m.tenant_id = c.tenant_id
               JOIN tenants t ON t.id = c.tenant_id AND t.status = 'active'
              WHERE c.id = ? AND m.user_id = ? AND m.status = 'active' AND m.revoked_at IS NULL
                AND m.valid_from <= ? AND m.valid_until > ?",
            [$contractId, $p->userId, $now, $now],
        );
        foreach ($rows as $row) {
            $type = (string) $row['mandate_type'];
            if (($p->userType !== (self::TYPE_FOR_MANDATE[$type] ?? '')) || !in_array($row['status'], self::EXTERNAL_VISIBLE_STATUS, true)) {
                continue;
            }
            if ($type !== 'counterparty' && $row['approved_version_id'] === null) {
                continue; // Revision/Notariat nur auf freigegebene Stände
            }
            $needed = self::MANDATE_ACTION[$type][$action] ?? null;
            $allowed = json_decode((string) $row['allowed_actions'], true);
            if ($needed === null || !is_array($allowed) || !in_array($needed, $allowed, true)) {
                continue;
            }
            return $row;
        }
        $this->deny($p, 'contract', $contractId, 'external_no_valid_mandate:' . $action);
        throw new NotFoundException();
    }

    // ---------------------------------------------------------------- Sichtbarkeit als SQL

    /**
     * SQL-Bedingung (mit Parametern), die genau die Verträge auswählt, die contract() für die Aktion
     * "view" liefern würde. Wird von Suche, Dashboard, Zählern, Autovervollständigung und Exporten
     * verwendet, damit nirgends Daten ausserhalb der Berechtigung auftauchen (auch keine Anzahlen).
     * Ein Test vergleicht diese Bedingung mit contract() für alle Rollen.
     *
     * @return array{0:string,1:list<mixed>}
     */
    public function visibleContractsSql(Principal $p, string $alias = 'c'): array
    {
        if (!preg_match('/^[a-z_]{1,10}$/', $alias)) {
            throw new \InvalidArgumentException('Alias');
        }
        $a = $alias;
        if ($p->isExternal()) {
            $type = array_search($p->userType, self::TYPE_FOR_MANDATE, true);
            if ($type === false) {
                return ['0 = 1', []];
            }
            $action = self::MANDATE_ACTION[$type]['view'];
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $st = implode(',', array_map(static fn (string $s): string => "'" . $s . "'", self::EXTERNAL_VISIBLE_STATUS));
            $sql = "EXISTS (SELECT 1 FROM external_mandate_contracts xmc
                      JOIN external_mandates xm ON xm.id = xmc.mandate_id AND xm.tenant_id = {$a}.tenant_id
                      JOIN tenants xt ON xt.id = {$a}.tenant_id AND xt.status = 'active'
                     WHERE xmc.contract_id = {$a}.id AND xm.user_id = ? AND xm.mandate_type = ?
                       AND xm.status = 'active' AND xm.revoked_at IS NULL AND xm.valid_from <= ? AND xm.valid_until > ?
                       AND JSON_CONTAINS(xm.allowed_actions, JSON_QUOTE(?))
                       AND {$a}.status IN ({$st})
                       AND (xm.mandate_type = 'counterparty' OR {$a}.approved_version_id IS NOT NULL))";
            return [$sql, [$p->userId, $type, $now, $now, $action]];
        }
        if ($p->tenantId === null || !$p->can('contract.view')) {
            return ['0 = 1', []];
        }
        if ($p->can('tenant.manage')) {
            return ["{$a}.tenant_id = ?", [$p->tenantId]];
        }
        $or = [
            "{$a}.owner_user_id = ?",
            "EXISTS (SELECT 1 FROM contract_acl xa WHERE xa.contract_id = {$a}.id AND xa.user_id = ?)",
            "EXISTS (SELECT 1 FROM approvals xap WHERE xap.contract_id = {$a}.id AND xap.approver_user_id = ?)",
        ];
        $params = [$p->tenantId, $p->userId, $p->userId, $p->userId];
        if ($p->departmentIds !== []) {
            $or[] = "({$a}.department_id IS NOT NULL AND {$a}.department_id IN (" . implode(',', array_map('intval', $p->departmentIds)) . '))';
        }
        if ($p->hasRole('contract_manager') || $p->hasRole('approver')) {
            $or[] = "{$a}.department_id IS NULL";
        }
        return ["{$a}.tenant_id = ? AND (" . implode(' OR ', $or) . ')', $params];
    }

    // ---------------------------------------------------------------- Versionen

    /**
     * Liefert eine Vertragsversion, sofern der Zugriff auf den Vertrag erlaubt ist.
     * Externe sehen nur gesperrte (unveränderliche) Versionen: Revision/Notariat nur die
     * freigegebene Version, Vertragspartner die freigegebene Version.
     * @return array<string,mixed>
     */
    public function version(Principal $p, int $versionId, string $action = 'view'): array
    {
        $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ?', [$versionId]);
        if ($v === null) {
            $this->deny($p, 'contract_version', $versionId, 'not_found');
            throw new NotFoundException();
        }
        $c = $this->contract($p, (int) $v['contract_id'], $action);
        if ((int) $c['tenant_id'] !== (int) $v['tenant_id']) {
            throw new NotFoundException();
        }
        if ($p->isExternal()) {
            $isApproved = $c['approved_version_id'] !== null && (int) $c['approved_version_id'] === $versionId;
            if ($v['state'] !== 'locked' || !$isApproved) {
                $this->deny($p, 'contract_version', $versionId, 'external_not_approved_version');
                throw new NotFoundException();
            }
            if ($p->userType === 'notary' && !$this->notaryCaseCoversVersion($p, $versionId)) {
                $this->deny($p, 'contract_version', $versionId, 'notary_no_case_for_version');
                throw new NotFoundException();
            }
        }
        return $v;
    }

    private function notaryCaseCoversVersion(Principal $p, int $versionId): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM notary_cases WHERE notary_user_id = ? AND contract_version_id = ? AND status <> 'prepared'",
            [$p->userId, $versionId],
        ) > 0;
    }

    // ---------------------------------------------------------------- Dokumente

    /** @return array<string,mixed> */
    public function document(Principal $p, int $documentId, string $action = 'download'): array
    {
        $d = $this->db->one('SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL', [$documentId]);
        if ($d === null) {
            $this->deny($p, 'document', $documentId, 'not_found');
            throw new NotFoundException();
        }
        $c = $this->contract($p, (int) $d['contract_id'], $action);
        if ((int) $c['tenant_id'] !== (int) $d['tenant_id']) {
            throw new NotFoundException();
        }
        if (!$p->isExternal()) {
            return $d;
        }
        // Externe: nur ausdrücklich sichtbare Dokumente; Versionsbezug muss die freigegebene Version sein.
        if ((int) $d['visible_to_external'] !== 1) {
            $this->deny($p, 'document', $documentId, 'external_not_visible');
            throw new NotFoundException();
        }
        if ($d['contract_version_id'] !== null && $c['approved_version_id'] !== null
            && (int) $d['contract_version_id'] !== (int) $c['approved_version_id']) {
            $this->deny($p, 'document', $documentId, 'external_not_approved_version');
            throw new NotFoundException();
        }
        if ($p->userType === 'notary') {
            $allowed = (int) $this->db->value(
                "SELECT COUNT(*) FROM notary_cases WHERE notary_user_id = ? AND document_id = ? AND status <> 'prepared'",
                [$p->userId, $documentId],
            );
            if ($allowed === 0) {
                $this->deny($p, 'document', $documentId, 'notary_not_assigned');
                throw new NotFoundException();
            }
        }
        return $d;
    }

    // ---------------------------------------------------------------- Kommentare

    /** @return list<string> sichtbare Kommentar-Sichtbarkeiten für den Handelnden */
    public function visibleCommentScopes(Principal $p): array
    {
        if (!$p->isExternal() && $p->can('comment.internal')) {
            return ['internal', 'shared'];
        }
        return ['shared'];
    }

    // ---------------------------------------------------------------- Hilfen

    private function deny(Principal $p, string $objectType, ?int $objectId, string $reason): void
    {
        $this->audit->log(
            'access.denied',
            'denied',
            $p->tenantId,
            $p->userId,
            $objectType,
            $objectId,
            ['reason' => $reason],
        );
    }
}
