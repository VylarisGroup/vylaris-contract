<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\HtmlSanitizer;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;

final class ContractService
{
    /** Vertragsinhaltliche Felder: nur im Status "draft" änderbar. */
    private const CONTRACTUAL_FIELDS = ['title', 'description', 'contract_type_id', 'governing_law', 'venue', 'start_date', 'end_date', 'notice_period_days', 'renewal_terms', 'auto_renew', 'renewal_period_days', 'value_amount', 'value_currency', 'payment_terms'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly WorkflowService $workflow,
        private readonly Clock $clock,
    ) {
    }

    // ------------------------------------------------------------------ Anlegen

    /** @param array<string,mixed> $in */
    public function create(Principal $p, array $in): int
    {
        $this->ac->require($p, 'contract.create');
        $tenantId = $this->tenantOf($p);
        $data = $this->validate($p, $tenantId, $in, true);

        return $this->db->transaction(function () use ($p, $tenantId, $data, $in): int {
            $number = $this->nextNumber($tenantId);
            $this->db->run(
                'INSERT INTO contracts (tenant_id, department_id, contract_number, title, description, contract_type_id, owner_user_id, governing_law, venue, start_date, end_date,
                    notice_period_days, renewal_terms, auto_renew, renewal_period_days, value_amount, value_currency, payment_terms, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',?)',
                [$tenantId, $data['department_id'], $number, $data['title'], $data['description'], $data['contract_type_id'], $data['owner_user_id'], $data['governing_law'], $data['venue'],
                    $data['start_date'], $data['end_date'], $data['notice_period_days'], $data['renewal_terms'], $data['auto_renew'], $data['renewal_period_days'],
                    $data['value_amount'], $data['value_currency'], $data['payment_terms'], $p->userId],
            );
            $id = $this->db->lastId();
            $versionId = $this->insertVersion($tenantId, $id, 1, null, '', '', $p->userId, null, null, null);
            $this->db->run('UPDATE contracts SET current_version_id = ? WHERE id = ?', [$versionId, $id]);
            $this->syncParties($tenantId, $id, $data['parties']);
            $this->syncTags($tenantId, $id, $data['tags']);
            $this->syncCustomFields($tenantId, $id, $data['custom_fields']);
            $this->audit->log('contract.created', 'success', $tenantId, $p->userId, 'contract', $id, ['number' => $number]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ Ändern

    /** @param array<string,mixed> $in */
    public function update(Principal $p, int $contractId, array $in): void
    {
        $c = $this->ac->contract($p, $contractId, 'edit');
        $tenantId = (int) $c['tenant_id'];
        $data = $this->validate($p, $tenantId, $in + $this->currentAsInput($c), false);

        $this->db->transaction(function () use ($p, $tenantId, $contractId, $data, $in): void {
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($c === null) {
                throw new NotFoundException();
            }
            if ($c['status'] === StatusMachine::ARCHIVED) {
                throw new BusinessRuleException('Archivierte Verträge können nicht geändert werden. Legen Sie einen Nachtrag an.');
            }
            $touchesContractual = false;
            foreach (self::CONTRACTUAL_FIELDS as $f) {
                if (array_key_exists($f, $in) && $this->differs($c[$f] ?? null, $data[$f] ?? null)) {
                    $touchesContractual = true;
                }
            }
            if (array_key_exists('parties', $in)) {
                $touchesContractual = true;
            }
            if ($touchesContractual && $c['status'] !== StatusMachine::DRAFT) {
                $this->audit->log('contract.update_rejected', 'denied', $c['tenant_id'] === null ? null : (int) $c['tenant_id'], $p->userId, 'contract', $contractId, ['status' => $c['status']]);
                throw new BusinessRuleException('Vertragsinhaltliche Angaben sind nur im Entwurf änderbar. Erstellen Sie eine neue Version oder einen Nachtrag.');
            }
            if ($touchesContractual) {
                $this->db->run(
                    'UPDATE contracts SET title=?, description=?, contract_type_id=?, governing_law=?, venue=?, start_date=?, end_date=?, notice_period_days=?, renewal_terms=?,
                        auto_renew=?, renewal_period_days=?, value_amount=?, value_currency=?, payment_terms=? WHERE id = ?',
                    [$data['title'], $data['description'], $data['contract_type_id'], $data['governing_law'], $data['venue'], $data['start_date'], $data['end_date'],
                        $data['notice_period_days'], $data['renewal_terms'], $data['auto_renew'], $data['renewal_period_days'], $data['value_amount'], $data['value_currency'],
                        $data['payment_terms'], $contractId],
                );
                if (array_key_exists('parties', $in)) {
                    $this->syncParties($tenantId, $contractId, $data['parties']);
                }
            }
            // Administrative Metadaten dürfen auch nach der Freigabe gepflegt werden (protokolliert).
            $this->db->run('UPDATE contracts SET department_id = ?, owner_user_id = ? WHERE id = ?', [$data['department_id'], $data['owner_user_id'], $contractId]);
            if (array_key_exists('tags', $in)) {
                $this->syncTags($tenantId, $contractId, $data['tags']);
            }
            if (array_key_exists('custom_fields', $in)) {
                $this->syncCustomFields($tenantId, $contractId, $data['custom_fields']);
            }
            $this->audit->log('contract.updated', 'success', $tenantId, $p->userId, 'contract', $contractId, ['fields' => array_values(array_intersect(array_keys($in), array_merge(self::CONTRACTUAL_FIELDS, ['parties', 'tags', 'custom_fields', 'department_id', 'owner_user_id'])))]);
        });
    }

    // ------------------------------------------------------------------ Inhalt / Versionen

    /**
     * Speichert den Vertragstext. Entwurfsversion wird in place aktualisiert; ist die aktuelle
     * Version gesperrt (freigegeben/abgelehnt/zurückgezogen), entsteht eine NEUE Version mit
     * Bezug zur Vorgängerversion. Freigaben der alten Version werden ungültig.
     *
     * @param array<string,mixed>|null $formAnswers
     */
    public function saveBody(Principal $p, int $contractId, string $bodyHtml, ?string $changeNote = null, ?array $formAnswers = null, ?int $templateVersionId = null): int
    {
        $c0 = $this->ac->contract($p, $contractId, 'edit');
        $tenantId = (int) $c0['tenant_id'];
        if (strlen($bodyHtml) > 2_000_000) {
            throw new ValidationException(['body' => 'Der Vertragstext ist zu lang.']);
        }
        $clean = HtmlSanitizer::clean($bodyHtml);
        $text = HtmlSanitizer::toText($clean);

        return $this->db->transaction(function () use ($p, $tenantId, $contractId, $clean, $text, $changeNote, $formAnswers, $templateVersionId): int {
            $c = $this->db->one('SELECT * FROM contracts WHERE id = ? FOR UPDATE', [$contractId]);
            if ($c === null) {
                throw new NotFoundException();
            }
            $status = (string) $c['status'];
            if (in_array($status, [StatusMachine::SIGNATURE, StatusMachine::ACTIVE], true)) {
                throw new BusinessRuleException('Dieser Vertrag ist in Signatur bzw. aktiv. Änderungen erfolgen über einen Nachtrag.');
            }
            if ($status === StatusMachine::REVIEW) {
                throw new BusinessRuleException('Der Vertrag ist in Prüfung. Ziehen Sie die Freigabeanfrage zuerst zurück.');
            }
            if (!in_array($status, [StatusMachine::DRAFT, StatusMachine::APPROVED, StatusMachine::REJECTED, StatusMachine::WITHDRAWN], true)) {
                throw new BusinessRuleException('Im aktuellen Status ist keine Bearbeitung möglich.');
            }
            $cur = $c['current_version_id'] === null ? null : $this->db->one('SELECT * FROM contract_versions WHERE id = ? FOR UPDATE', [(int) $c['current_version_id']]);
            $formJson = $formAnswers === null ? null : json_encode($formAnswers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if ($cur !== null && $cur['state'] === 'draft' && $status === StatusMachine::DRAFT) {
                $this->db->run(
                    "UPDATE contract_versions SET body_html = ?, body_text = ?, change_note = COALESCE(?, change_note), form_answers = COALESCE(?, form_answers),
                        template_version_id = COALESCE(?, template_version_id) WHERE id = ? AND state = 'draft'",
                    [$clean, $text, $changeNote, $formJson, $templateVersionId, (int) $cur['id']],
                );
                $this->audit->log('contract.draft_saved', 'success', $tenantId, $p->userId, 'contract_version', (int) $cur['id'], ['version_no' => (int) $cur['version_no']]);
                return (int) $cur['id'];
            }

            // Neue Version, weil die aktuelle gesperrt ist oder der Status zurückgeführt wird.
            $next = (int) $this->db->value('SELECT COALESCE(MAX(version_no),0) + 1 FROM contract_versions WHERE contract_id = ?', [$contractId]);
            $newId = $this->insertVersion($tenantId, $contractId, $next, $cur === null ? null : (int) $cur['id'], $clean, $text, $p->userId, $changeNote, $formJson, $templateVersionId);
            if ($cur !== null) {
                $this->db->run("UPDATE approvals SET status = 'invalidated' WHERE contract_version_id = ? AND status IN ('pending','approved')", [(int) $cur['id']]);
            }
            $this->db->run('UPDATE contracts SET current_version_id = ?, approved_version_id = NULL WHERE id = ?', [$newId, $contractId]);
            if ($status !== StatusMachine::DRAFT) {
                $this->workflow->move($contractId, StatusMachine::DRAFT, $p->userId, 'Neue Version erstellt', ['new_version_id' => $newId]);
            }
            $this->audit->log('contract.version_created', 'success', $tenantId, $p->userId, 'contract_version', $newId, ['version_no' => $next, 'previous_version_id' => $cur === null ? null : (int) $cur['id']]);
            return $newId;
        });
    }

    // ------------------------------------------------------------------ Archivierung / Nachträge

    public function archive(Principal $p, int $contractId, string $reason): void
    {
        $c = $this->ac->contract($p, $contractId, 'archive');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Bitte einen Grund für die Archivierung angeben.']);
        }
        $this->workflow->move($contractId, StatusMachine::ARCHIVED, $p->userId, mb_substr(trim($reason), 0, 300));
        $this->audit->log('contract.archived', 'success', (int) $c['tenant_id'], $p->userId, 'contract', $contractId);
    }

    /**
     * Nachtrag / Änderungsantrag: neuer Entwurf, verknüpft mit dem ursprünglichen Vertrag.
     * Nur für Verträge, die bereits freigegeben oder aktiv sind.
     * @param array<string,mixed> $in
     */
    public function createAmendment(Principal $p, int $parentId, string $relationType, array $in): int
    {
        if (!in_array($relationType, ['amendment', 'change_request'], true)) {
            throw new ValidationException(['relation_type' => 'Ungültige Art des Nachtrags.']);
        }
        $parent = $this->ac->contract($p, $parentId, 'edit');
        $this->ac->require($p, 'contract.create');
        if (!in_array($parent['status'], [StatusMachine::APPROVED, StatusMachine::SIGNATURE, StatusMachine::ACTIVE, StatusMachine::EXPIRED], true)) {
            throw new BusinessRuleException('Nachträge sind nur zu freigegebenen oder aktiven Verträgen möglich.');
        }
        $defaults = [
            'title' => 'Nachtrag zu ' . $parent['title'],
            'contract_type_id' => (int) $parent['contract_type_id'],
            'owner_user_id' => (int) $parent['owner_user_id'],
            'department_id' => $parent['department_id'] === null ? null : (int) $parent['department_id'],
            'governing_law' => $parent['governing_law'],
            'venue' => $parent['venue'],
        ];
        $id = $this->create($p, $in + $defaults);
        $this->db->run('UPDATE contracts SET parent_contract_id = ?, relation_type = ? WHERE id = ? AND tenant_id = ?', [$parentId, $relationType, $id, (int) $parent['tenant_id']]);
        $this->audit->log('contract.amendment_created', 'success', (int) $parent['tenant_id'], $p->userId, 'contract', $id, ['parent_contract_id' => $parentId, 'relation' => $relationType]);
        return $id;
    }

    // ------------------------------------------------------------------ Lesen

    /** @return array<string,mixed> */
    public function get(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        $id = (int) $c['id'];
        $c['parties'] = $this->db->all(
            'SELECT cp.id AS link_id, cp.role_label, cp.must_sign, cp.sign_order, c.id AS counterparty_id, c.name, c.party_type
               FROM contract_parties cp JOIN counterparties c ON c.id = cp.counterparty_id AND c.tenant_id = cp.tenant_id
              WHERE cp.contract_id = ? AND cp.tenant_id = ? ORDER BY cp.sign_order, cp.id',
            [$id, (int) $c['tenant_id']],
        );
        $c['versions'] = $this->db->all(
            'SELECT id, version_no, previous_version_id, state, content_hash, locked_at, change_note, created_by, created_at FROM contract_versions WHERE contract_id = ? ORDER BY version_no DESC',
            [$id],
        );
        if (!$p->isExternal()) {
            $c['tags'] = array_column($this->db->all('SELECT t.name FROM contract_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contract_id = ? ORDER BY t.name', [$id]), 'name');
        } else {
            // Externe sehen nur die freigegebene, gesperrte Version.
            $approved = $c['approved_version_id'] === null ? -1 : (int) $c['approved_version_id'];
            $c['versions'] = array_values(array_filter($c['versions'], static fn (array $v): bool => (int) $v['id'] === $approved));
            unset($c['allowed_actions']);
        }
        $this->audit->log('contract.viewed', 'success', (int) $c['tenant_id'], $p->userId, 'contract', $id, ['external' => $p->isExternal()]);
        return $c;
    }

    // ------------------------------------------------------------------ intern

    private function tenantOf(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new ForbiddenException();
        }
        return $p->tenantId;
    }

    private function nextNumber(int $tenantId): string
    {
        $year = (int) $this->clock->now()->format('Y');
        $prefix = (string) $this->db->value('SELECT contract_number_prefix FROM tenants WHERE id = ?', [$tenantId]);
        $this->db->run(
            'INSERT INTO contract_number_sequences (tenant_id, year, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$tenantId, $year],
        );
        return sprintf('%s-%d-%06d', $prefix, $year, $this->db->lastId());
    }

    private function insertVersion(int $tenantId, int $contractId, int $no, ?int $prev, string $html, string $text, int $userId, ?string $note, ?string $formJson, ?int $templateVersionId): int
    {
        $this->db->run(
            'INSERT INTO contract_versions (tenant_id, contract_id, version_no, previous_version_id, body_html, body_text, template_version_id, form_answers, change_note, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tenantId, $contractId, $no, $prev, $html, $text, $templateVersionId, $formJson, $note, $userId],
        );
        return $this->db->lastId();
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function currentAsInput(array $c): array
    {
        $out = [];
        foreach (array_merge(self::CONTRACTUAL_FIELDS, ['department_id', 'owner_user_id']) as $f) {
            $out[$f] = $c[$f] ?? null;
        }
        return $out;
    }

    private function differs(mixed $a, mixed $b): bool
    {
        return (string) ($a ?? '') !== (string) ($b ?? '');
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function validate(Principal $p, int $tenantId, array $in, bool $isCreate): array
    {
        $err = [];
        $str = static function (mixed $v, int $max): ?string {
            if ($v === null) {
                return null;
            }
            $s = trim((string) $v);
            return $s === '' ? null : mb_substr($s, 0, $max);
        };
        $title = $str($in['title'] ?? null, 255);
        if ($title === null) {
            $err['title'] = 'Bitte einen Titel angeben.';
        }
        $typeId = (int) ($in['contract_type_id'] ?? 0);
        if ($this->db->one('SELECT id FROM contract_types WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)', [$typeId, $tenantId]) === null) {
            $err['contract_type_id'] = 'Bitte eine gültige Vertragsart wählen.';
        }
        $ownerId = (int) ($in['owner_user_id'] ?? $p->userId);
        $ownerOk = $this->db->one(
            "SELECT u.id FROM users u JOIN tenant_memberships tm ON tm.user_id = u.id AND tm.tenant_id = ? AND tm.status = 'active'
              WHERE u.id = ? AND u.status = 'active' AND u.user_type = 'internal' LIMIT 1",
            [$tenantId, $ownerId],
        );
        if ($ownerOk === null) {
            $err['owner_user_id'] = 'Die zuständige Person muss aktives internes Mitglied des Mandanten sein.';
        }
        $deptId = ($in['department_id'] ?? null) === null || $in['department_id'] === '' ? null : (int) $in['department_id'];
        if ($deptId !== null) {
            if ($this->db->one('SELECT id FROM departments WHERE id = ? AND tenant_id = ?', [$deptId, $tenantId]) === null) {
                $err['department_id'] = 'Ungültige Abteilung.';
            } elseif (!$p->can('tenant.manage') && !in_array($deptId, $p->departmentIds, true)) {
                $err['department_id'] = 'Sie gehören dieser Abteilung nicht an.';
            }
        }
        $date = static function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
            return ($d !== false && $d->format('Y-m-d') === (string) $v) ? $d->format('Y-m-d') : '__invalid__';
        };
        $start = $date($in['start_date'] ?? null);
        $end = $date($in['end_date'] ?? null);
        if ($start === '__invalid__') {
            $err['start_date'] = 'Ungültiges Datum (Format JJJJ-MM-TT).';
            $start = null;
        }
        if ($end === '__invalid__') {
            $err['end_date'] = 'Ungültiges Datum (Format JJJJ-MM-TT).';
            $end = null;
        }
        if ($start !== null && $end !== null && $end < $start) {
            $err['end_date'] = 'Das Vertragsende liegt vor dem Vertragsbeginn.';
        }
        $intOrNull = static function (mixed $v, int $max, string $field, array &$err): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            if (!preg_match('/^\d{1,6}$/', (string) $v) || (int) $v > $max) {
                $err[$field] = 'Bitte eine ganze Zahl zwischen 0 und ' . $max . ' angeben.';
                return null;
            }
            return (int) $v;
        };
        $notice = $intOrNull($in['notice_period_days'] ?? null, 3650, 'notice_period_days', $err);
        $renewDays = $intOrNull($in['renewal_period_days'] ?? null, 3650, 'renewal_period_days', $err);
        $amount = null;
        if (($in['value_amount'] ?? '') !== '' && ($in['value_amount'] ?? null) !== null) {
            $raw = str_replace(["'", ' '], '', (string) $in['value_amount']);
            if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $raw)) {
                $err['value_amount'] = 'Bitte einen gültigen Betrag angeben (z. B. 12500.00).';
            } else {
                $amount = $raw;
            }
        }
        $currency = $str($in['value_currency'] ?? null, 10); // bewusst nicht auf 3 kürzen: Fehleingaben ablehnen, nicht umdeuten
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $err['value_currency'] = 'Bitte einen dreistelligen Währungscode angeben (z. B. CHF).';
            }
        }
        if ($amount !== null && $currency === null) {
            $err['value_currency'] = 'Bitte eine Währung zum Vertragswert angeben.';
        }

        $parties = [];
        foreach ((array) ($in['parties'] ?? []) as $i => $row) {
            $cpId = (int) ($row['counterparty_id'] ?? 0);
            $role = $str($row['role_label'] ?? null, 80);
            if ($role === null || $this->db->one('SELECT id FROM counterparties WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$cpId, $tenantId]) === null) {
                $err["parties.{$i}"] = 'Ungültiger Vertragspartner oder fehlende Rolle.';
                continue;
            }
            $parties[] = ['counterparty_id' => $cpId, 'role_label' => $role, 'must_sign' => !empty($row['must_sign']) ? 1 : 0, 'sign_order' => max(1, (int) ($row['sign_order'] ?? 1))];
        }
        $tags = [];
        foreach ((array) ($in['tags'] ?? []) as $t) {
            $t = mb_strtolower(trim((string) $t));
            if ($t !== '' && mb_strlen($t) <= 80) {
                $tags[$t] = $t;
            }
        }
        if (count($tags) > 20) {
            $err['tags'] = 'Maximal 20 Tags je Vertrag.';
        }
        $custom = $this->validateCustomFields($tenantId, (array) ($in['custom_fields'] ?? []), $err);

        if ($err !== []) {
            throw new ValidationException($err);
        }
        return [
            'title' => $title, 'description' => $str($in['description'] ?? null, 20000), 'contract_type_id' => $typeId, 'owner_user_id' => $ownerId, 'department_id' => $deptId,
            'governing_law' => $str($in['governing_law'] ?? null, 120), 'venue' => $str($in['venue'] ?? null, 190), 'start_date' => $start, 'end_date' => $end,
            'notice_period_days' => $notice, 'renewal_terms' => $str($in['renewal_terms'] ?? null, 500), 'auto_renew' => !empty($in['auto_renew']) ? 1 : 0,
            'renewal_period_days' => $renewDays, 'value_amount' => $amount, 'value_currency' => $currency, 'payment_terms' => $str($in['payment_terms'] ?? null, 500),
            'parties' => $parties, 'tags' => array_values($tags), 'custom_fields' => $custom,
        ];
    }

    /** @param array<string,mixed> $values @param array<string,string> $err @return array<int,?string> field_id => value */
    private function validateCustomFields(int $tenantId, array $values, array &$err): array
    {
        $out = [];
        $defs = $this->db->all('SELECT * FROM custom_field_definitions WHERE tenant_id = ?', [$tenantId]);
        foreach ($defs as $d) {
            $code = (string) $d['code'];
            $raw = $values[$code] ?? null;
            $empty = $raw === null || $raw === '';
            if ($empty) {
                if ((int) $d['is_required'] === 1 && array_key_exists('custom_fields', ['custom_fields' => $values]) && $values !== []) {
                    $err["custom.{$code}"] = $d['label'] . ' ist ein Pflichtfeld.';
                }
                continue;
            }
            $v = trim((string) $raw);
            $ok = match ($d['field_type']) {
                'number' => is_numeric($v),
                'date' => \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false,
                'boolean' => in_array($v, ['0', '1'], true),
                'select' => in_array($v, (array) json_decode((string) ($d['options'] ?? '[]'), true), true),
                default => mb_strlen($v) <= 2000,
            };
            if (!$ok) {
                $err["custom.{$code}"] = 'Ungültiger Wert für ' . $d['label'] . '.';
                continue;
            }
            $out[(int) $d['id']] = $v;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $parties */
    private function syncParties(int $tenantId, int $contractId, array $parties): void
    {
        $this->db->run('DELETE FROM contract_parties WHERE contract_id = ? AND tenant_id = ?', [$contractId, $tenantId]);
        foreach ($parties as $row) {
            $this->db->run(
                'INSERT INTO contract_parties (tenant_id, contract_id, counterparty_id, role_label, must_sign, sign_order) VALUES (?,?,?,?,?,?)',
                [$tenantId, $contractId, $row['counterparty_id'], $row['role_label'], $row['must_sign'], $row['sign_order']],
            );
        }
    }

    /** @param list<string> $tags */
    private function syncTags(int $tenantId, int $contractId, array $tags): void
    {
        $this->db->run('DELETE FROM contract_tags WHERE contract_id = ?', [$contractId]);
        foreach ($tags as $t) {
            $this->db->run('INSERT INTO tags (tenant_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$tenantId, $t]);
            $this->db->run('INSERT IGNORE INTO contract_tags (contract_id, tag_id) VALUES (?, ?)', [$contractId, $this->db->lastId()]);
        }
    }

    /** @param array<int,?string> $values */
    private function syncCustomFields(int $tenantId, int $contractId, array $values): void
    {
        $this->db->run('DELETE FROM custom_field_values WHERE contract_id = ?', [$contractId]);
        foreach ($values as $fieldId => $v) {
            $this->db->run('INSERT INTO custom_field_values (contract_id, field_id, value_text) VALUES (?,?,?)', [$contractId, $fieldId, $v]);
        }
    }
}
