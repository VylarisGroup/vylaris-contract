<?php

declare(strict_types=1);

namespace App\Modules\Connections;

use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Core\HtmlSanitizer;
use App\Services\AccessControl;

/**
 * Lesender Zugriff auf Verträge anderer Mandanten, in denen der eigene Mandant als verbundene Gegenpartei eingetragen ist.
 * Bedingungen (alle gemeinsam): aktive Verbindung, eigener Mandant ist über eine verbundene Gegenpartei Vertragspartei,
 * der Vertrag ist mindestens freigegeben (nie Entwürfe, Prüfung, abgelehnt/zurückgezogen/abgebrochen). Gezeigt werden nur
 * Vertragsdaten, der freigegebene Text, Parteien und unterzeichnete bzw. ausdrücklich geteilte Dokumente –
 * keine Kommentare, Aufgaben, Freigabevorgänge, internen Notizen, Beschreibung oder Anhänge. Der Zugriff wird protokolliert.
 */
final class SharedContractService
{
    private const VISIBLE = ['approved', 'signature', 'active', 'expired', 'archived'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly DocumentStore $store,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $p): array
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'contract.view');
        return $this->db->all(
            'SELECT DISTINCT c.id, c.contract_number, c.title, c.status, c.start_date, c.end_date, t.name AS owner_name, cp.role_label
               FROM contracts c
               JOIN contract_parties cp ON cp.contract_id = c.id AND cp.tenant_id = c.tenant_id
               JOIN counterparties k ON k.id = cp.counterparty_id AND k.tenant_id = cp.tenant_id AND k.linked_tenant_id = ?
               JOIN tenant_connections n ON n.status = \'active\' AND ((n.requester_tenant_id = c.tenant_id AND n.target_tenant_id = ?) OR (n.target_tenant_id = c.tenant_id AND n.requester_tenant_id = ?))
               JOIN tenants t ON t.id = c.tenant_id
              WHERE c.status IN (\'' . implode("','", self::VISIBLE) . '\') ORDER BY c.updated_at DESC LIMIT 200',
            [$me, $me, $me],
        );
    }

    /** @return array<string,mixed> */
    public function get(Principal $p, int $id): array
    {
        $c = $this->visible($p, $id);
        $parties = $this->db->all(
            'SELECT k.name, cp.role_label, cp.must_sign FROM contract_parties cp JOIN counterparties k ON k.id = cp.counterparty_id AND k.tenant_id = cp.tenant_id WHERE cp.contract_id = ? AND cp.tenant_id = ? ORDER BY cp.id',
            [$id, (int) $c['tenant_id']],
        );
        $body = '';
        if ($c['approved_version_id'] !== null) {
            $body = HtmlSanitizer::clean((string) $this->db->value('SELECT body_html FROM contract_versions WHERE id = ? AND contract_id = ?', [(int) $c['approved_version_id'], $id]));
        }
        $docs = $this->db->all(
            "SELECT id, original_filename, kind, size_bytes, created_at FROM documents WHERE contract_id = ? AND tenant_id = ? AND (kind = 'signed' OR visible_to_external = 1) AND kind NOT IN ('evidence') ORDER BY id",
            [$id, (int) $c['tenant_id']],
        );
        $this->audit->log('shared_contract.viewed', 'success', $p->tenantId, $p->userId, 'contract', $id, ['owner_tenant' => (int) $c['tenant_id']]);
        unset($c['description'], $c['created_by'], $c['owner_user_id'], $c['department_id']);
        return $c + ['parties' => $parties, 'body_html' => $body, 'documents' => $docs];
    }

    /** @return array{bytes:string,filename:string,mime:string} */
    public function document(Principal $p, int $contractId, int $docId): array
    {
        $c = $this->visible($p, $contractId);
        $d = $this->db->one(
            "SELECT * FROM documents WHERE id = ? AND contract_id = ? AND tenant_id = ? AND (kind = 'signed' OR visible_to_external = 1) AND kind NOT IN ('evidence')",
            [$docId, $contractId, (int) $c['tenant_id']],
        );
        if ($d === null) {
            throw new NotFoundException();
        }
        $this->audit->log('shared_contract.document_downloaded', 'success', $p->tenantId, $p->userId, 'document', $docId, ['owner_tenant' => (int) $c['tenant_id']]);
        return ['bytes' => $this->store->readBytes($d), 'filename' => (string) $d['original_filename'], 'mime' => (string) $d['mime_type']];
    }

    /** @return array<string,mixed> */
    private function visible(Principal $p, int $id): array
    {
        $me = $this->tenant($p);
        $this->ac->require($p, 'contract.view');
        $c = $this->db->one(
            'SELECT c.*, t.name AS owner_name FROM contracts c JOIN tenants t ON t.id = c.tenant_id
              WHERE c.id = ? AND c.status IN (\'' . implode("','", self::VISIBLE) . '\')
                AND EXISTS (SELECT 1 FROM contract_parties cp JOIN counterparties k ON k.id = cp.counterparty_id AND k.tenant_id = cp.tenant_id
                             WHERE cp.contract_id = c.id AND cp.tenant_id = c.tenant_id AND k.linked_tenant_id = ?)
                AND EXISTS (SELECT 1 FROM tenant_connections n WHERE n.status = \'active\'
                             AND ((n.requester_tenant_id = c.tenant_id AND n.target_tenant_id = ?) OR (n.target_tenant_id = c.tenant_id AND n.requester_tenant_id = ?)))',
            [$id, $me, $me, $me],
        );
        if ($c === null || (int) $c['tenant_id'] === $me) {
            $this->audit->log('shared_contract.denied', 'denied', $me, $p->userId, 'contract', $id);
            throw new NotFoundException();
        }
        return $c;
    }

    private function tenant(Principal $p): int
    {
        if ($p->tenantId === null || $p->isExternal()) {
            throw new ForbiddenException();
        }
        return $p->tenantId;
    }
}
