<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\ContractService;
use App\Services\AccessControl;

/**
 * Vorlagen und Klauseln mit Versionierung und fachlichem Prüfstatus (unreviewed → in_review → approved).
 * Fachliche Prüfung ist Vier-Augen: Wer die Version erstellt hat, kann sie nicht selbst freigeben.
 * Die Plattform ersetzt keine juristische Prüfung – "approved" bedeutet nur, dass ein berechtigter
 * Benutzer des Mandanten die Vorlage intern geprüft hat.
 */
final class TemplateService
{
    private const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly ContractService $contracts,
    ) {
    }

    // ------------------------------------------------------------------ Vorlagen

    /**
     * @param list<array<string,mixed>> $fieldSchema
     * @return int template_id
     */
    public function create(Principal $p, string $name, int $contractTypeId, string $jurisdiction, string $body, array $fieldSchema): int
    {
        $tenantId = $this->tenant($p, 'template.manage');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['name' => 'Bitte einen Namen (max. 190 Zeichen) angeben.']);
        }
        if (preg_match('/^[A-Z]{2}(-[A-Z]{2,3})?$|^EU$/', $jurisdiction) !== 1) {
            throw new ValidationException(['jurisdiction' => 'Ungültiges Rechtsgebiet (z. B. CH, DE, EU).']);
        }
        $this->assertType($tenantId, $contractTypeId);
        [$clean, $schema] = $this->prepareContent($tenantId, $body, $fieldSchema);
        return $this->db->transaction(function () use ($p, $tenantId, $name, $contractTypeId, $jurisdiction, $clean, $schema): int {
            $this->db->run('INSERT INTO contract_templates (tenant_id, name, contract_type_id, jurisdiction, created_by) VALUES (?,?,?,?,?)', [$tenantId, $name, $contractTypeId, $jurisdiction, $p->userId]);
            $id = $this->db->lastId();
            $vid = $this->insertVersion($tenantId, $id, 1, $clean, $schema, $p->userId);
            $this->db->run("UPDATE contract_templates SET current_version_id = ?, review_status = 'unreviewed' WHERE id = ?", [$vid, $id]);
            $this->audit->log('template.created', 'success', $tenantId, $p->userId, 'template', $id);
            return $id;
        });
    }

    /** Neue Version (frühere bleiben unverändert erhalten). Prüfstatus beginnt wieder bei "unreviewed". */
    public function addVersion(Principal $p, int $templateId, string $body, array $fieldSchema): int
    {
        $tenantId = $this->tenant($p, 'template.manage');
        $t = $this->load($tenantId, $templateId);
        if ($t['archived_at'] !== null) {
            throw new BusinessRuleException('Archivierte Vorlagen können nicht geändert werden.');
        }
        [$clean, $schema] = $this->prepareContent($tenantId, $body, $fieldSchema);
        return $this->db->transaction(function () use ($p, $tenantId, $t, $clean, $schema): int {
            $this->db->one('SELECT id FROM contract_templates WHERE id = ? AND tenant_id = ? FOR UPDATE', [(int) $t['id'], $tenantId]);
            $no = 1 + (int) $this->db->value('SELECT MAX(version_no) FROM template_versions WHERE template_id = ?', [(int) $t['id']]);
            $vid = $this->insertVersion($tenantId, (int) $t['id'], $no, $clean, $schema, $p->userId);
            $this->db->run("UPDATE contract_templates SET current_version_id = ?, review_status = 'unreviewed' WHERE id = ?", [$vid, (int) $t['id']]);
            $this->audit->log('template.version_added', 'success', $tenantId, $p->userId, 'template', (int) $t['id'], ['version_no' => $no]);
            return $vid;
        });
    }

    public function requestReview(Principal $p, int $templateId): void
    {
        $tenantId = $this->tenant($p, 'template.manage');
        $t = $this->load($tenantId, $templateId);
        $v = $this->currentVersion($tenantId, $t);
        if ($v['review_status'] !== 'unreviewed') {
            throw new BusinessRuleException('Die aktuelle Version ist bereits in Prüfung oder freigegeben.');
        }
        $this->setReview($tenantId, $t, $v, 'in_review', null);
        $this->audit->log('template.review_requested', 'success', $tenantId, $p->userId, 'template', $templateId, ['version_no' => $v['version_no']]);
    }

    public function decideReview(Principal $p, int $templateId, bool $approve, string $comment = ''): void
    {
        $tenantId = $this->tenant($p, 'template.approve');
        $t = $this->load($tenantId, $templateId);
        $v = $this->currentVersion($tenantId, $t);
        if ($v['review_status'] !== 'in_review') {
            throw new BusinessRuleException('Die Version befindet sich nicht in Prüfung.');
        }
        if ((int) $v['created_by'] === $p->userId) {
            $this->audit->log('template.review_denied', 'denied', $tenantId, $p->userId, 'template', $templateId, ['reason' => 'four_eyes']);
            throw new BusinessRuleException('Vier-Augen-Prinzip: Die Ersteller:in einer Version kann sie nicht selbst freigeben.');
        }
        if (!$approve && trim($comment) === '') {
            throw new ValidationException(['comment' => 'Bitte eine Begründung für die Ablehnung angeben.']);
        }
        $this->setReview($tenantId, $t, $v, $approve ? 'approved' : 'unreviewed', $p->userId);
        $this->audit->log($approve ? 'template.approved' : 'template.review_rejected', 'success', $tenantId, $p->userId, 'template', $templateId, ['version_no' => $v['version_no'], 'comment' => mb_substr($comment, 0, 300)]);
    }

    public function archive(Principal $p, int $templateId): void
    {
        $tenantId = $this->tenant($p, 'template.manage');
        $this->load($tenantId, $templateId);
        $this->db->run('UPDATE contract_templates SET archived_at = UTC_TIMESTAMP(6) WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$templateId, $tenantId]);
        $this->audit->log('template.archived', 'success', $tenantId, $p->userId, 'template', $templateId);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p, bool $includeArchived = false): array
    {
        $tenantId = $this->tenant($p, 'template.view');
        return $this->db->all(
            'SELECT t.id, t.name, t.jurisdiction, t.review_status, t.archived_at, ct.name AS type_name, v.version_no
               FROM contract_templates t
               JOIN contract_types ct ON ct.id = t.contract_type_id
               LEFT JOIN template_versions v ON v.id = t.current_version_id
              WHERE t.tenant_id = ?' . ($includeArchived ? '' : ' AND t.archived_at IS NULL') . ' ORDER BY t.name',
            [$tenantId],
        );
    }

    /** @return array<string,mixed> */
    public function get(Principal $p, int $templateId): array
    {
        $tenantId = $this->tenant($p, 'template.view');
        $t = $this->load($tenantId, $templateId);
        $t['versions'] = $this->db->all('SELECT id, version_no, review_status, reviewed_at, created_at, created_by, content_hash FROM template_versions WHERE template_id = ? AND tenant_id = ? ORDER BY version_no DESC', [$templateId, $tenantId]);
        $v = $this->currentVersion($tenantId, $t);
        $v['field_schema'] = json_decode((string) $v['field_schema'], true) ?: [];
        $t['current'] = $v;
        return $t;
    }

    /**
     * Erzeugt einen Vertragsentwurf aus einer Vorlage. Nicht freigegebene Vorlagen nur mit ausdrücklicher Bestätigung.
     *
     * @param array<string,mixed> $contractInput Eingaben für ContractService::create (Titel, Typ, …)
     * @param array<string,mixed> $values Feldwerte
     * @return array{contract_id:int,warnings:list<string>}
     */
    public function instantiate(Principal $p, int $templateId, array $contractInput, array $values, bool $allowUnreviewed = false): array
    {
        $this->ac->require($p, 'contract.create');
        $tenantId = $this->tenant($p, 'template.view');
        $t = $this->load($tenantId, $templateId);
        if ($t['archived_at'] !== null) {
            throw new BusinessRuleException('Archivierte Vorlagen können nicht verwendet werden.');
        }
        $v = $this->currentVersion($tenantId, $t);
        $warnings = [];
        if ($v['review_status'] !== 'approved') {
            if (!$allowUnreviewed) {
                throw new BusinessRuleException('Diese Vorlage wurde noch nicht fachlich freigegeben. Verwendung nur mit ausdrücklicher Bestätigung.');
            }
            $warnings[] = 'Die Vorlage ist nicht freigegeben (Status: ' . $v['review_status'] . ').';
        }
        $schema = json_decode((string) $v['field_schema'], true) ?: [];
        $norm = $this->normalizeValues($schema, $values);
        $clauses = $this->clauseMap($tenantId);
        $rendered = TemplateEngine::render((string) $v['body'], $norm, $clauses);
        foreach ($rendered['used_clauses'] as $code) {
            if ($clauses[$code]['review_status'] !== 'approved') {
                $warnings[] = 'Klausel „' . $code . '“ ist nicht freigegeben.';
            }
        }
        $contractInput += ['contract_type_id' => (int) $t['contract_type_id'], 'governing_law' => null];
        $cid = $this->db->transaction(function () use ($p, $contractInput, $rendered, $v, $norm, $t, $allowUnreviewed, $warnings): int {
            $id = $this->contracts->create($p, $contractInput);
            $this->contracts->saveBody($p, $id, $rendered['html'], 'Aus Vorlage „' . $t['name'] . '“ v' . $v['version_no'], $norm, (int) $v['id']);
            $this->audit->log('template.instantiated', 'success', (int) $t['tenant_id'], $p->userId, 'contract', $id, [
                'template_id' => (int) $t['id'], 'version_no' => (int) $v['version_no'], 'unreviewed_confirmed' => $allowUnreviewed && $warnings !== [],
            ]);
            return $id;
        });
        return ['contract_id' => $cid, 'warnings' => $warnings];
    }

    /** Vorschau ohne Speichern. @return array{html:string,warnings:list<string>} */
    public function preview(Principal $p, int $templateId, array $values): array
    {
        $tenantId = $this->tenant($p, 'template.view');
        $t = $this->load($tenantId, $templateId);
        $v = $this->currentVersion($tenantId, $t);
        $schema = json_decode((string) $v['field_schema'], true) ?: [];
        $clauses = $this->clauseMap($tenantId);
        $r = TemplateEngine::render((string) $v['body'], $this->normalizeValues($schema, $values, false), $clauses);
        $warnings = $v['review_status'] !== 'approved' ? ['Vorlage nicht freigegeben.'] : [];
        return ['html' => HtmlSanitizer::clean($r['html']), 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ Klauseln

    public function saveClause(Principal $p, string $code, string $title, string $body, ?int $clauseId = null): int
    {
        $tenantId = $this->tenant($p, 'template.manage');
        $code = trim($code);
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code) !== 1) {
            throw new ValidationException(['code' => 'Code: 1–64 Zeichen (Buchstaben, Ziffern, _ . -).']);
        }
        if (trim($title) === '' || mb_strlen($title) > 190) {
            throw new ValidationException(['title' => 'Bitte einen Titel angeben.']);
        }
        $clean = HtmlSanitizer::clean($body);
        if (trim(strip_tags($clean)) === '') {
            throw new ValidationException(['body' => 'Die Klausel darf nicht leer sein.']);
        }
        TemplateEngine::analyze($clean, $this->allFieldCodesPermissive($clean)); // Struktur prüfen
        if ($clauseId === null) {
            $exists = $this->db->value('SELECT id FROM template_clauses WHERE tenant_id = ? AND code = ?', [$tenantId, $code]);
            if ($exists) {
                throw new ValidationException(['code' => 'Dieser Code existiert bereits.']);
            }
            $this->db->run('INSERT INTO template_clauses (tenant_id, code, title, body) VALUES (?,?,?,?)', [$tenantId, $code, $title, $clean]);
            $id = $this->db->lastId();
            $this->audit->log('clause.created', 'success', $tenantId, $p->userId, 'clause', $id);
            return $id;
        }
        $row = $this->db->one('SELECT id FROM template_clauses WHERE id = ? AND tenant_id = ?', [$clauseId, $tenantId]);
        if ($row === null) {
            throw new NotFoundException();
        }
        // Änderung macht die Klausel wieder "ungeprüft".
        $this->db->run("UPDATE template_clauses SET title = ?, body = ?, review_status = 'unreviewed' WHERE id = ? AND tenant_id = ?", [$title, $clean, $clauseId, $tenantId]);
        $this->audit->log('clause.updated', 'success', $tenantId, $p->userId, 'clause', $clauseId);
        return $clauseId;
    }

    public function setClauseReview(Principal $p, int $clauseId, string $status): void
    {
        if (!in_array($status, ['in_review', 'approved', 'unreviewed'], true)) {
            throw new ValidationException(['status' => 'Ungültiger Status.']);
        }
        $tenantId = $this->tenant($p, $status === 'approved' ? 'template.approve' : 'template.manage');
        $n = $this->db->execute('UPDATE template_clauses SET review_status = ? WHERE id = ? AND tenant_id = ?', [$status, $clauseId, $tenantId]);
        $exists = $this->db->value('SELECT id FROM template_clauses WHERE id = ? AND tenant_id = ?', [$clauseId, $tenantId]);
        if (!$exists) {
            throw new NotFoundException();
        }
        $this->audit->log('clause.review_' . $status, 'success', $tenantId, $p->userId, 'clause', $clauseId, ['changed' => $n]);
    }

    /** @return list<array<string,mixed>> */
    public function listClauses(Principal $p): array
    {
        $tenantId = $this->tenant($p, 'template.view');
        return $this->db->all('SELECT id, code, title, review_status, archived_at FROM template_clauses WHERE tenant_id = ? AND archived_at IS NULL ORDER BY code', [$tenantId]);
    }

    // ------------------------------------------------------------------ intern

    private function tenant(Principal $p, string $permission): int
    {
        $this->ac->require($p, $permission);
        if ($p->tenantId === null || $p->isExternal()) {
            throw new NotFoundException();
        }
        return $p->tenantId;
    }

    /** @return array<string,mixed> */
    private function load(int $tenantId, int $templateId): array
    {
        $t = $this->db->one('SELECT * FROM contract_templates WHERE id = ? AND tenant_id = ?', [$templateId, $tenantId]);
        if ($t === null) {
            throw new NotFoundException();
        }
        return $t;
    }

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function currentVersion(int $tenantId, array $t): array
    {
        $v = $this->db->one('SELECT * FROM template_versions WHERE id = ? AND tenant_id = ?', [(int) $t['current_version_id'], $tenantId]);
        if ($v === null) {
            throw new NotFoundException();
        }
        return $v;
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $v */
    private function setReview(int $tenantId, array $t, array $v, string $status, ?int $reviewer): void
    {
        $this->db->transaction(function () use ($tenantId, $t, $v, $status, $reviewer): void {
            $this->db->run('UPDATE template_versions SET review_status = ?, reviewed_by = ?, reviewed_at = IF(? IS NULL, NULL, UTC_TIMESTAMP(6)) WHERE id = ? AND tenant_id = ?', [$status, $reviewer, $reviewer, (int) $v['id'], $tenantId]);
            $this->db->run('UPDATE contract_templates SET review_status = ? WHERE id = ? AND tenant_id = ?', [$status, (int) $t['id'], $tenantId]);
        });
    }

    private function assertType(int $tenantId, int $typeId): void
    {
        $ok = $this->db->value('SELECT id FROM contract_types WHERE id = ? AND (tenant_id IS NULL OR tenant_id = ?)', [$typeId, $tenantId]);
        if (!$ok) {
            throw new ValidationException(['contract_type_id' => 'Ungültiger Vertragstyp.']);
        }
    }

    /**
     * @param list<array<string,mixed>> $fieldSchema
     * @return array{0:string,1:list<array<string,mixed>>}
     */
    private function prepareContent(int $tenantId, string $body, array $fieldSchema): array
    {
        $schema = $this->validateSchema($fieldSchema);
        $clean = HtmlSanitizer::clean($body);
        if (trim(strip_tags($clean)) === '') {
            throw new ValidationException(['body' => 'Der Vorlagentext darf nicht leer sein.']);
        }
        $info = TemplateEngine::analyze($clean, array_column($schema, 'code'));
        foreach ($info['clauses'] as $code) {
            $ok = $this->db->value('SELECT id FROM template_clauses WHERE tenant_id = ? AND code = ? AND archived_at IS NULL', [$tenantId, $code]);
            if (!$ok) {
                throw new ValidationException(['body' => 'Die Klausel „' . $code . '“ existiert nicht.']);
            }
        }
        return [$clean, $schema];
    }

    /** @param list<array<string,mixed>> $schema @return list<array<string,mixed>> */
    private function validateSchema(array $schema): array
    {
        $out = [];
        $seen = [];
        if (count($schema) > 100) {
            throw new ValidationException(['fields' => 'Maximal 100 Felder.']);
        }
        foreach ($schema as $f) {
            $code = (string) ($f['code'] ?? '');
            $type = (string) ($f['type'] ?? 'text');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
                throw new ValidationException(['fields' => 'Feldcode „' . $code . '“ ungültig (a–z, 0–9, _).']);
            }
            if (isset($seen[$code])) {
                throw new ValidationException(['fields' => 'Feldcode doppelt: ' . $code]);
            }
            $seen[$code] = true;
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new ValidationException(['fields' => 'Feldtyp ungültig bei ' . $code]);
            }
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 120) {
                throw new ValidationException(['fields' => 'Beschriftung fehlt bei ' . $code]);
            }
            $opts = [];
            if ($type === 'select') {
                $opts = array_values(array_filter(array_map(static fn ($o) => trim((string) $o), (array) ($f['options'] ?? [])), static fn ($o) => $o !== ''));
                if ($opts === [] || count($opts) > 50) {
                    throw new ValidationException(['fields' => 'Auswahlfeld ' . $code . ' braucht 1–50 Optionen.']);
                }
            }
            $q = trim((string) ($f['question'] ?? ''));
            $hlp = trim((string) ($f['help'] ?? ''));
            if (mb_strlen($q) > 200 || mb_strlen($hlp) > 400) {
                throw new ValidationException(['fields' => 'Frage (max. 200) oder Hinweis (max. 400 Zeichen) zu lang bei ' . $code]);
            }
            $out[] = ['code' => $code, 'label' => $label, 'type' => $type, 'required' => !empty($f['required']), 'options' => $opts, 'question' => $q, 'help' => $hlp];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $schema
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function normalizeValues(array $schema, array $values, bool $enforceRequired = true): array
    {
        $out = [];
        $errors = [];
        foreach ($schema as $f) {
            $code = (string) $f['code'];
            $raw = $values[$code] ?? '';
            $raw = is_scalar($raw) ? trim((string) $raw) : '';
            if ($f['type'] === 'boolean') {
                $raw = in_array(strtolower($raw), ['1', 'true', 'on', 'ja', 'yes'], true) ? '1' : '';
            }
            if ($raw === '' && $enforceRequired && !empty($f['required']) && $f['type'] !== 'boolean') {
                $errors['field_' . $code] = 'Pflichtfeld: ' . $f['label'];
            }
            if ($raw !== '') {
                if (mb_strlen($raw) > 5000) {
                    $errors['field_' . $code] = 'Zu lang: ' . $f['label'];
                } elseif ($f['type'] === 'number' && preg_match('/^-?\d{1,15}([.,]\d{1,6})?$/', $raw) !== 1) {
                    $errors['field_' . $code] = 'Zahl erwartet: ' . $f['label'];
                } elseif ($f['type'] === 'date' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1 || !checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4)))) {
                    $errors['field_' . $code] = 'Datum (JJJJ-MM-TT) erwartet: ' . $f['label'];
                } elseif ($f['type'] === 'select' && !in_array($raw, (array) $f['options'], true)) {
                    $errors['field_' . $code] = 'Ungültige Auswahl: ' . $f['label'];
                }
            }
            $out[$code] = $raw;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /** @return array<string,array{body:string,review_status:string,title:string}> */
    private function clauseMap(int $tenantId): array
    {
        $map = [];
        foreach ($this->db->all('SELECT code, title, body, review_status FROM template_clauses WHERE tenant_id = ? AND archived_at IS NULL', [$tenantId]) as $r) {
            $map[(string) $r['code']] = ['body' => (string) $r['body'], 'review_status' => (string) $r['review_status'], 'title' => (string) $r['title']];
        }
        return $map;
    }

    /** Klauseln dürfen Felder der einbindenden Vorlage verwenden: Namen nicht vorab prüfbar. @return list<string> */
    private function allFieldCodesPermissive(string $body): array
    {
        preg_match_all('/\{\{\s*(?:#(?:if|unless)\s+)?([a-z][a-z0-9_]*)/', $body, $m);
        return array_values(array_unique($m[1]));
    }

    /** @param list<array<string,mixed>> $schema */
    private function insertVersion(int $tenantId, int $templateId, int $no, string $body, array $schema, int $userId): int
    {
        $this->db->run(
            'INSERT INTO template_versions (tenant_id, template_id, version_no, body, field_schema, content_hash, created_by) VALUES (?,?,?,?,?,?,?)',
            [$tenantId, $templateId, $no, $body, json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), hash('sha256', $body . "\n" . json_encode($schema, JSON_UNESCAPED_UNICODE)), $userId],
        );
        return $this->db->lastId();
    }
}
