<?php

declare(strict_types=1);

namespace App\Modules\Mandates;

use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Security\AuthService;
use App\Services\AccessControl;

/**
 * Externe Mandate: einzige Grundlage für den Zugriff Externer (Vertragspartner, Revision, Notariat).
 * Befristet, widerrufbar, auf konkrete Verträge und Aktionen beschränkt. Der Widerruf wirkt sofort,
 * weil Mandate bei jedem Zugriff serverseitig geprüft werden (keine Zwischenspeicherung).
 */
final class MandateService
{
    /** @var array<string,array{user_type:string,role:string,actions:list<string>,max_days:int}> */
    public const TYPES = [
        'counterparty' => ['user_type' => 'external_party', 'role' => 'external_party', 'actions' => ['read', 'download', 'sign', 'comment'], 'max_days' => 365],
        'audit' => ['user_type' => 'auditor', 'role' => 'auditor', 'actions' => ['read_versions', 'read_history', 'read_evidence', 'download_attachments', 'annotate', 'export_report'], 'max_days' => 120],
        'notary' => ['user_type' => 'notary', 'role' => 'notary', 'actions' => ['read', 'update_case', 'upload_return'], 'max_days' => 180],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @param list<int> $contractIds
     * @param list<string> $actions
     */
    public function create(Principal $p, string $type, string $email, string $displayName, ?string $organization, array $contractIds, array $actions, string $validFrom, string $validUntil, ?int $responsibleUserId = null): int
    {
        $this->ac->require($p, 'mandate.manage');
        $tenantId = $this->tenant($p);
        $spec = self::TYPES[$type] ?? throw new ValidationException(['type' => 'Ungültiger Mandatstyp.']);
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            throw new ValidationException(['email' => 'Gültige E-Mail-Adresse erforderlich.']);
        }
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new ValidationException(['display_name' => 'Name erforderlich.']);
        }
        $actions = array_values(array_unique($actions));
        if ($actions === [] || array_diff($actions, $spec['actions']) !== []) {
            throw new ValidationException(['actions' => 'Ungültige oder fehlende Aktionen für diesen Mandatstyp.']);
        }
        $from = $this->parseDate($validFrom, 'valid_from');
        $until = $this->parseDate($validUntil, 'valid_until');
        if ($until <= $from) {
            throw new ValidationException(['valid_until' => 'Das Ende muss nach dem Beginn liegen.']);
        }
        if ($until > $from->modify('+' . $spec['max_days'] . ' days')) {
            throw new ValidationException(['valid_until' => 'Die maximale Mandatsdauer beträgt ' . $spec['max_days'] . ' Tage.']);
        }
        if ($until <= $this->clock->now()) {
            throw new ValidationException(['valid_until' => 'Das Mandat wäre sofort abgelaufen.']);
        }
        $contractIds = array_values(array_unique(array_map('intval', $contractIds)));
        if ($contractIds === [] || count($contractIds) > 200) {
            throw new ValidationException(['contracts' => 'Mindestens ein und höchstens 200 Verträge auswählen.']);
        }
        foreach ($contractIds as $cid) {
            $this->ac->contract($p, $cid, 'view'); // nur Verträge, die der Vergebende selbst sehen darf (sonst NotFound)
        }
        $responsible = $responsibleUserId ?? $p->userId;
        if (!$this->db->value("SELECT 1 FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active'", [$tenantId, $responsible])) {
            throw new ValidationException(['responsible_user_id' => 'Verantwortliche Person gehört nicht zum Mandanten.']);
        }

        $result = $this->db->transaction(function () use ($p, $tenantId, $type, $spec, $email, $displayName, $organization, $contractIds, $actions, $from, $until, $responsible): array {
            $u = $this->db->one('SELECT id, user_type, status FROM users WHERE email = ?', [$email]);
            $invite = false;
            if ($u === null) {
                $this->db->run("INSERT INTO users (email, display_name, user_type, status) VALUES (?,?,?, 'pending')", [$email, $displayName, $spec['user_type']]);
                $userId = $this->db->lastId();
                $invite = true;
            } else {
                if ($u['user_type'] !== $spec['user_type']) {
                    throw new BusinessRuleException('Diese E-Mail-Adresse gehört zu einem Konto anderer Art und kann nicht als ' . $spec['role'] . ' eingesetzt werden.');
                }
                $userId = (int) $u['id'];
                $invite = $u['status'] === 'pending';
            }
            $this->db->run(
                "INSERT IGNORE INTO tenant_memberships (tenant_id, user_id, role_id, created_by) SELECT ?, ?, id, ? FROM roles WHERE code = ?",
                [$tenantId, $userId, $p->userId, $spec['role']],
            );
            $this->db->run("UPDATE tenant_memberships SET status = 'active' WHERE tenant_id = ? AND user_id = ? AND status <> 'active' AND role_id = (SELECT id FROM roles WHERE code = ?)", [$tenantId, $userId, $spec['role']]);
            $this->db->run(
                'INSERT INTO external_mandates (tenant_id, mandate_type, user_id, organization, responsible_user_id, allowed_actions, valid_from, valid_until, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$tenantId, $type, $userId, $organization !== null ? mb_substr(trim($organization), 0, 190) : null, $responsible, json_encode($actions), $from->format('Y-m-d H:i:s.u'), $until->format('Y-m-d H:i:s.u'), $p->userId],
            );
            $mid = $this->db->lastId();
            foreach ($contractIds as $cid) {
                $this->db->run('INSERT INTO external_mandate_contracts (mandate_id, contract_id) VALUES (?,?)', [$mid, $cid]);
            }
            $this->audit->log('mandate.created', 'success', $tenantId, $p->userId, 'mandate', $mid, [
                'type' => $type, 'user_id' => $userId, 'contracts' => count($contractIds), 'valid_until' => $until->format('Y-m-d'), 'actions' => $actions,
            ]);
            return ['id' => $mid, 'user_id' => $userId, 'invite' => $invite];
        });
        if ($result['invite']) {
            $this->auth->sendInvitation($result['user_id'], $p->displayName !== '' ? $p->displayName : 'Ihr Ansprechpartner', 'externer Zugang (' . $type . ')');
        }
        return $result['id'];
    }

    public function revoke(Principal $p, int $mandateId, string $reason): void
    {
        $this->ac->require($p, 'mandate.manage');
        $tenantId = $this->tenant($p);
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Bitte einen Grund angeben.']);
        }
        $n = $this->db->execute("UPDATE external_mandates SET status = 'revoked', revoked_at = ? WHERE id = ? AND tenant_id = ? AND status = 'active'", [$this->clock->now()->format('Y-m-d H:i:s.u'), $mandateId, $tenantId]);
        if ($n === 0) {
            if (!$this->db->value('SELECT id FROM external_mandates WHERE id = ? AND tenant_id = ?', [$mandateId, $tenantId])) {
                throw new NotFoundException();
            }
            throw new BusinessRuleException('Das Mandat ist bereits widerrufen.');
        }
        $this->audit->log('mandate.revoked', 'success', $tenantId, $p->userId, 'mandate', $mandateId, ['reason' => mb_substr($reason, 0, 300)]);
    }

    /** Verlängert (nie über die maximale Gesamtdauer des Typs hinaus). */
    public function extend(Principal $p, int $mandateId, string $newUntil): void
    {
        $this->ac->require($p, 'mandate.manage');
        $tenantId = $this->tenant($p);
        $m = $this->db->one('SELECT * FROM external_mandates WHERE id = ? AND tenant_id = ?', [$mandateId, $tenantId]);
        if ($m === null) {
            throw new NotFoundException();
        }
        if ($m['status'] !== 'active') {
            throw new BusinessRuleException('Widerrufene Mandate können nicht verlängert werden.');
        }
        $until = $this->parseDate($newUntil, 'valid_until');
        $from = new \DateTimeImmutable((string) $m['valid_from'], new \DateTimeZone('UTC'));
        $max = self::TYPES[(string) $m['mandate_type']]['max_days'];
        if ($until > $from->modify("+{$max} days") || $until <= $this->clock->now()) {
            throw new ValidationException(['valid_until' => 'Ungültiges Enddatum (max. ' . $max . ' Tage ab Beginn, in der Zukunft).']);
        }
        $this->db->run('UPDATE external_mandates SET valid_until = ? WHERE id = ?', [$until->format('Y-m-d H:i:s.u'), $mandateId]);
        $this->audit->log('mandate.extended', 'success', $tenantId, $p->userId, 'mandate', $mandateId, ['valid_until' => $until->format('Y-m-d')]);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p): array
    {
        $this->ac->require($p, 'mandate.manage');
        $tenantId = $this->tenant($p);
        return $this->db->all(
            "SELECT m.id, m.mandate_type, m.organization, m.valid_from, m.valid_until, m.status, m.allowed_actions, u.display_name, u.email,
                    (SELECT COUNT(*) FROM external_mandate_contracts mc WHERE mc.mandate_id = m.id) AS contract_count
               FROM external_mandates m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY m.id DESC",
            [$tenantId],
        );
    }

    /** Hilfsfunktion für Notariat: sorgt für ein gültiges Notariatsmandat auf einen Vertrag. */
    public function ensureNotaryMandate(Principal $p, int $contractId, int $notaryUserId): void
    {
        $tenantId = $this->tenant($p);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $has = $this->db->value(
            "SELECT 1 FROM external_mandates m JOIN external_mandate_contracts mc ON mc.mandate_id = m.id
              WHERE m.tenant_id = ? AND m.user_id = ? AND m.mandate_type = 'notary' AND mc.contract_id = ? AND m.status = 'active' AND m.valid_from <= ? AND m.valid_until > ?",
            [$tenantId, $notaryUserId, $contractId, $now, $now],
        );
        if ($has) {
            return;
        }
        $this->db->run(
            "INSERT INTO external_mandates (tenant_id, mandate_type, user_id, responsible_user_id, allowed_actions, valid_from, valid_until, created_by) VALUES (?, 'notary', ?, ?, ?, ?, ?, ?)",
            [$tenantId, $notaryUserId, $p->userId, json_encode(['read', 'update_case', 'upload_return']), $now, $this->clock->now()->modify('+90 days')->format('Y-m-d H:i:s.u'), $p->userId],
        );
        $this->db->run('INSERT INTO external_mandate_contracts (mandate_id, contract_id) VALUES (?,?)', [$this->db->lastId(), $contractId]);
        $this->audit->log('mandate.created', 'success', $tenantId, $p->userId, 'mandate', $this->db->lastId() ?: null, ['type' => 'notary', 'auto' => true, 'contract_id' => $contractId]);
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }

    private function parseDate(string $s, string $field): \DateTimeImmutable
    {
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}(T| )\d{2}:\d{2}(:\d{2})?$|^\d{4}-\d{2}-\d{2}$/', trim($s)) !== 1) {
                throw new \Exception('format');
            }
            return new \DateTimeImmutable(trim($s), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ValidationException([$field => 'Datum im Format JJJJ-MM-TT erforderlich.']);
        }
    }
}
