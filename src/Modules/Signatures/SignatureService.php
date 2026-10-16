<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Approvals\ApprovalService;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\WorkflowService;
use App\Modules\Documents\ContractDocumentService;
use App\Modules\Documents\DocumentStore;
use App\Services\AccessControl;

/**
 * Signaturvorgänge. Grundsätze:
 *  - Anfrage nur für die FREIGEGEBENE, unveränderte Version und das daraus abgeleitete,
 *    einmalig erzeugte PDF (Hash in der Anfrage gebunden).
 *  - Ein Erfolg wird nur gemeldet, wenn der Anbieter "signed" meldet UND ein PDF geliefert wurde.
 *    Fehler, Timeouts und unbekannte Werte ändern nie den Vertragsstatus zu "aktiv".
 *  - Sandbox-Vorgänge können einen Vertrag nie aktivieren.
 *  - Die erreichte Stufe wird nur eingetragen, wenn sie belegt ist (sonst UNKNOWN); eine
 *    qualifizierte Signatur (QES) wird nie behauptet.
 *  - Externe Aufrufe laufen ausserhalb von Datenbanktransaktionen.
 */
final class SignatureService
{
    private const OPEN = ['prepared', 'submitted', 'partially_signed'];

    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly WorkflowService $workflow,
        private readonly ContractDocumentService $documents,
        private readonly DocumentStore $store,
        private readonly SignatureProvider $provider,
    ) {
    }

    /**
     * @param list<SignerSpec> $signers
     * @return int signature_requests.id
     */
    public function request(Principal $p, int $contractId, string $level, array $signers, string $message = ''): int
    {
        $c = $this->ac->contract($p, $contractId, 'request_signature');
        $tenantId = (int) $c['tenant_id'];
        if (!in_array($level, ['SES', 'AES', 'QES'], true)) {
            throw new ValidationException(['level' => 'Ungültige Signaturstufe.']);
        }
        if (!in_array($level, $this->provider->supportedLevels(), true)) {
            throw new ValidationException(['level' => 'Die gewählte Signaturstufe wird vom Anbieter (' . $this->provider->name() . ') nicht angeboten.']);
        }
        $this->validateSigners($signers);

        $prepared = $this->db->transaction(function () use ($p, $contractId, $tenantId, $level, $signers): array {
            $cl = $this->db->one('SELECT * FROM contracts WHERE id = ? AND tenant_id = ? FOR UPDATE', [$contractId, $tenantId]);
            if ($cl === null) {
                throw new NotFoundException();
            }
            if ($cl['status'] !== 'approved' || $cl['approved_version_id'] === null) {
                throw new BusinessRuleException('Eine Signatur kann nur für einen freigegebenen Vertrag angefordert werden.');
            }
            $versionId = (int) $cl['approved_version_id'];
            $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ? AND contract_id = ? AND tenant_id = ?', [$versionId, $contractId, $tenantId]);
            if ($v === null || $v['state'] !== 'locked' || !hash_equals((string) $v['content_hash'], ApprovalService::hashBody((string) $v['body_html']))) {
                $this->audit->log('integrity.violation', 'failure', $tenantId, $p->userId, 'contract_version', $versionId, ['where' => 'signature_request']);
                throw new BusinessRuleException('Integritätsprüfung der freigegebenen Version fehlgeschlagen. Signatur nicht möglich.');
            }
            $open = (int) $this->db->value('SELECT COUNT(*) FROM signature_requests WHERE contract_id = ? AND status IN (\'prepared\',\'submitted\',\'partially_signed\')', [$contractId]);
            if ($open > 0) {
                throw new BusinessRuleException('Es läuft bereits ein Signaturvorgang für diesen Vertrag.');
            }
            $doc = $this->documents->signableDocument($contractId, $versionId, $p->userId);
            $this->store->verifyIntegrity($doc);
            $this->db->run(
                'INSERT INTO signature_requests (tenant_id, contract_id, contract_version_id, document_id, document_sha256, provider, mode, requested_level, status, signers, created_by)
                 VALUES (?,?,?,?,?,?,?,?,\'prepared\',?,?)',
                [$tenantId, $contractId, $versionId, (int) $doc['id'], (string) $doc['sha256'], $this->provider->name(), $this->provider->mode(), $level,
                    json_encode(array_map(static fn (SignerSpec $s): array => $s->toArray(), $signers), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $p->userId],
            );
            $rid = $this->db->lastId();
            $this->workflow->move($contractId, 'signature', $p->userId, 'Signaturanfrage', ['signature_request_id' => $rid]);
            $this->event($rid, 'prepared', null, 'Vorgang vorbereitet');
            return ['rid' => $rid, 'doc' => $doc, 'title' => (string) $cl['title'], 'number' => (string) $cl['contract_number']];
        });

        $rid = (int) $prepared['rid'];
        $doc = $prepared['doc'];
        $idem = hash('sha256', 'sigreq|' . $this->provider->name() . '|' . $rid);
        try {
            $spec = new ProviderRequestSpec(
                $prepared['number'] . ' – ' . $prepared['title'],
                $message,
                $this->store->readBytes($doc),
                (string) $doc['sha256'],
                $signers,
                $level,
                $idem,
                (string) $rid,
            );
            $ext = $this->provider->createRequest($spec);
            if ($ext === '') {
                throw new ProviderException('Leere Vorgangs-ID vom Anbieter.');
            }
        } catch (\Throwable $e) {
            $this->apiLog('create_request', $idem, $rid, $e instanceof ProviderException ? $e->httpStatus : null, false, $e->getMessage());
            // Kein falscher Erfolg: Vorgang als fehlgeschlagen markieren, Vertrag zurück auf "freigegeben".
            $this->db->transaction(function () use ($rid, $contractId, $p, $e): void {
                $this->db->run("UPDATE signature_requests SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 480), $rid]);
                $this->event($rid, 'create_failed', null, mb_substr($e->getMessage(), 0, 480));
                $this->workflow->move($contractId, 'approved', $p->userId, 'Signaturanfrage fehlgeschlagen');
            });
            $this->audit->log('signature.request_failed', 'failure', $tenantId, $p->userId, 'signature_request', $rid, ['provider' => $this->provider->name()]);
            throw new BusinessRuleException('Die Signaturanfrage konnte nicht an den Anbieter übermittelt werden. Der Vertrag bleibt freigegeben; bitte später erneut versuchen.');
        }
        $this->apiLog('create_request', $idem, $rid, 200, true, null);
        $this->db->run("UPDATE signature_requests SET status = 'submitted', external_transaction_id = ? WHERE id = ?", [$ext, $rid]);
        $this->event($rid, 'submitted', null, 'An Anbieter übermittelt');
        $this->audit->log('signature.requested', 'success', $tenantId, $p->userId, 'signature_request', $rid, [
            'provider' => $this->provider->name(), 'mode' => $this->provider->mode(), 'level' => $level, 'signers' => count($signers),
            'document_sha256' => (string) $doc['sha256'],
        ]);
        return $rid;
    }

    /** Fragt den Anbieter ab und übernimmt das Ergebnis. Gibt den (neuen) Status zurück. */
    public function refresh(Principal $p, int $requestId): string
    {
        $r = $this->load($requestId, $p);
        $this->ac->contract($p, (int) $r['contract_id'], 'request_signature');
        return $this->refreshRow($r, $p->userId);
    }

    /** Für Cron: alle offenen Vorgänge dieses Anbieters. @return array{checked:int,changed:int,errors:int} */
    public function refreshOpen(int $limit = 50): array
    {
        $rows = $this->db->all(
            "SELECT * FROM signature_requests WHERE status IN ('submitted','partially_signed') AND provider = ? AND mode = ? ORDER BY COALESCE(last_polled_at, created_at) ASC LIMIT " . (int) $limit,
            [$this->provider->name(), $this->provider->mode()],
        );
        $out = ['checked' => 0, 'changed' => 0, 'errors' => 0];
        foreach ($rows as $r) {
            $out['checked']++;
            try {
                if ($this->refreshRow($r, null) !== $r['status']) {
                    $out['changed']++;
                }
            } catch (\Throwable) {
                $out['errors']++;
            }
        }
        return $out;
    }

    /** Für den öffentlichen Unterzeichnungslink: Ergebnis des Anbieters ohne angemeldete Person übernehmen. */
    public function syncRequest(int $requestId): void
    {
        $r = $this->db->one('SELECT * FROM signature_requests WHERE id = ?', [$requestId]);
        if ($r !== null && $r['provider'] === $this->provider->name() && $r['mode'] === $this->provider->mode()) {
            $this->refreshRow($r, null);
        }
    }

    /** Schickt den aktuell Einladenen einen neuen Link (nur Anbieter, die selbst einladen). */
    public function remind(Principal $p, int $requestId): int
    {
        $r = $this->load($requestId, $p);
        $this->ac->contract($p, (int) $r['contract_id'], 'request_signature');
        if (!($this->provider instanceof SupportsReminders)) {
            throw new BusinessRuleException('Dieser Anbieter unterstützt keine Erinnerungen über die Plattform.');
        }
        if (!in_array($r['status'], ['submitted', 'partially_signed'], true) || $r['external_transaction_id'] === null) {
            throw new BusinessRuleException('Der Vorgang ist nicht offen.');
        }
        $this->assertProvider($r);
        try {
            $n = $this->provider->remind((string) $r['external_transaction_id']);
        } catch (ProviderException $e) {
            throw new BusinessRuleException($e->getMessage());
        }
        $this->event($requestId, 'reminded', null, $n . ' Einladung(en) erneut versendet');
        $this->audit->log('signature.reminded', 'success', (int) $r['tenant_id'], $p->userId, 'signature_request', $requestId, ['count' => $n]);
        return $n;
    }

    public function cancel(Principal $p, int $requestId, string $reason): void
    {
        $r = $this->load($requestId, $p);
        $this->ac->contract($p, (int) $r['contract_id'], 'request_signature');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Bitte einen Grund angeben.']);
        }
        if (!in_array($r['status'], ['submitted', 'partially_signed'], true) || $r['external_transaction_id'] === null) {
            throw new BusinessRuleException('Dieser Vorgang kann nicht zurückgezogen werden.');
        }
        $this->assertProvider($r);
        $idem = hash('sha256', 'withdraw|' . $requestId);
        try {
            $this->provider->withdraw((string) $r['external_transaction_id'], $reason);
        } catch (ProviderException $e) {
            $this->apiLog('withdraw', $idem, $requestId, $e->httpStatus, false, $e->getMessage());
            $this->audit->log('signature.withdraw_failed', 'failure', (int) $r['tenant_id'], $p->userId, 'signature_request', $requestId);
            throw new BusinessRuleException('Der Rückzug konnte beim Anbieter nicht bestätigt werden; der Vorgang bleibt unverändert.');
        }
        $this->apiLog('withdraw', $idem, $requestId, 200, true, null);
        $this->closeRequest($r, 'cancelled', $p->userId, 'Zurückgezogen: ' . mb_substr($reason, 0, 300));
    }

    /** @return array<string,mixed> */
    /**
     * Teilnehmerstatus (nur Anbieter, die ihn melden) und Erinnerungsmöglichkeit für die Vertragsansicht.
     * @return array{signer_status:list<array<string,mixed>>,can_remind:bool}
     */
    public function signerInfo(Principal $p, int $requestId, string $status): array
    {
        $r = $this->load($requestId, $p);
        $out = ['signer_status' => [], 'can_remind' => false];
        if (!$this->provider instanceof ReportsSignerStatus || $r['external_transaction_id'] === null || $r['provider'] !== $this->provider->name()) {
            return $out;
        }
        try {
            $out['signer_status'] = $this->provider->signerStatus((string) $r['external_transaction_id']);
        } catch (\Throwable) {
        }
        $out['can_remind'] = $this->provider instanceof SupportsReminders && in_array($status, ['submitted', 'partially_signed'], true);
        return $out;
    }

    public function get(Principal $p, int $requestId): array
    {
        $r = $this->load($requestId, $p);
        $this->ac->contract($p, (int) $r['contract_id'], 'view');
        $r['events'] = $this->db->all('SELECT event_type, detail, created_at FROM signature_events WHERE signature_request_id = ? ORDER BY id', [$requestId]);
        $r['evidence'] = $this->db->all('SELECT evidence_type, document_id, achieved_level, validation_status, provider_reference, is_sandbox, created_at FROM signature_evidence WHERE signature_request_id = ? ORDER BY id', [$requestId]);
        $r['display'] = self::describe($r);
        return $r + $this->signerInfo($p, $requestId, (string) $r['status']);
    }

    /**
     * Ehrliche Anzeige-Beschreibung. Ein Vorgang gilt nur als "unterzeichnet", wenn er live
     * abgeschlossen ist; die Prüfung der Signatur ist separat ausgewiesen.
     *
     * @param array<string,mixed> $r
     * @return array{label:string,legal_valid:bool,notice:string}
     */
    public static function describe(array $r): array
    {
        $sandbox = $r['mode'] === 'sandbox';
        $label = match ($r['status']) {
            'prepared' => 'Vorbereitet', 'submitted' => 'Wartet auf Unterzeichnung', 'partially_signed' => 'Teilweise unterzeichnet',
            'signed' => 'Vom Anbieter als unterzeichnet gemeldet', 'declined' => 'Abgelehnt', 'expired' => 'Abgelaufen',
            'cancelled' => 'Zurückgezogen', default => 'Fehlgeschlagen',
        };
        $notice = $sandbox
            ? 'TESTMODUS – keine rechtsgültige Signatur.'
            : ($r['provider'] === 'internal'
                ? ($r['status'] === 'signed' ? 'Einfache elektronische Signatur (SES) der Plattform mit Siegel und Signaturprotokoll; keine qualifizierte Signatur. Ob sie für diesen Vertrag genügt, ist fachlich zu prüfen.' : '')
                : ($r['status'] === 'signed' ? 'Gemeldet vom Anbieter; die Signatur wurde von dieser Plattform nicht unabhängig geprüft.' : ''));
        return ['label' => $label, 'legal_valid' => false, 'notice' => $notice];
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $r */
    private function refreshRow(array $r, ?int $actorId): string
    {
        if (!in_array($r['status'], ['submitted', 'partially_signed'], true) || $r['external_transaction_id'] === null) {
            return (string) $r['status'];
        }
        $this->assertProvider($r);
        $rid = (int) $r['id'];
        $idem = hash('sha256', 'status|' . $rid . '|' . bin2hex(random_bytes(4)));
        try {
            $st = $this->provider->fetchStatus((string) $r['external_transaction_id']);
        } catch (ProviderException $e) {
            $this->apiLog('fetch_status', $idem, $rid, $e->httpStatus, false, $e->getMessage());
            $this->db->run('UPDATE signature_requests SET last_polled_at = UTC_TIMESTAMP(6), last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 480), $rid]);
            throw new BusinessRuleException('Der Status konnte beim Anbieter nicht abgefragt werden.');
        }
        $this->apiLog('fetch_status', $idem, $rid, 200, true, null);
        $this->db->run('UPDATE signature_requests SET last_polled_at = UTC_TIMESTAMP(6), last_error = NULL WHERE id = ?', [$rid]);

        switch ($st->state) {
            case 'open':
                return (string) $r['status'];
            case 'signed':
                return $this->completeSigned($r, $st, $actorId);
            case 'declined':
            case 'expired':
            case 'cancelled':
            case 'failed':
                $this->closeRequest($r, $st->state, $actorId, 'Status vom Anbieter: ' . $st->state, $st);
                return $st->state;
            default:
                // Unbekannter Wert: nichts ableiten, nur festhalten.
                $this->event($rid, 'unknown_status', $st->detail['payload_sha256'] ?? null, 'Unbekannter Anbieterstatus: ' . mb_substr((string) $st->rawValue, 0, 60));
                $this->audit->log('signature.unknown_status', 'failure', (int) $r['tenant_id'], $actorId, 'signature_request', $rid);
                return (string) $r['status'];
        }
    }

    /** @param array<string,mixed> $r */
    private function completeSigned(array $r, ProviderStatus $st, ?int $actorId): string
    {
        $rid = (int) $r['id'];
        $tenantId = (int) $r['tenant_id'];
        $idem = hash('sha256', 'fetchdoc|' . $rid);
        try {
            $doc = $this->provider->fetchSignedDocument((string) $r['external_transaction_id'], $st);
            $evidence = $this->provider->fetchEvidence((string) $r['external_transaction_id'], $st);
        } catch (ProviderException $e) {
            $this->apiLog('fetch_signed', $idem, $rid, $e->httpStatus, false, $e->getMessage());
            $this->audit->log('signature.document_fetch_failed', 'failure', $tenantId, $actorId, 'signature_request', $rid);
            throw new BusinessRuleException('Das signierte Dokument konnte nicht abgerufen werden; der Vorgang bleibt offen und wird erneut versucht.');
        }
        if ($doc === null || !str_starts_with($doc->bytes, '%PDF-')) {
            // "signed" ohne verwertbares Dokument ist kein Erfolg.
            $this->event($rid, 'signed_without_document', null, 'Anbieter meldet signiert, lieferte aber kein gültiges PDF');
            $this->audit->log('signature.signed_without_document', 'failure', $tenantId, $actorId, 'signature_request', $rid);
            throw new BusinessRuleException('Der Anbieter meldet „signiert“, lieferte aber kein gültiges Dokument. Der Vorgang wird nicht als abgeschlossen geführt.');
        }
        $sandbox = $r['mode'] === 'sandbox' || $doc->kind !== 'signed';
        $validation = $this->provider->validate($doc->bytes);

        $this->db->transaction(function () use ($r, $rid, $tenantId, $doc, $evidence, $validation, $sandbox, $actorId): void {
            $cur = $this->db->one('SELECT status FROM signature_requests WHERE id = ? FOR UPDATE', [$rid]);
            if ($cur === null || !in_array($cur['status'], ['submitted', 'partially_signed'], true)) {
                return; // bereits von einem parallelen Lauf abgeschlossen (idempotent)
            }
            $docId = $this->store->storeBytes($tenantId, (int) $r['contract_id'], (int) $r['contract_version_id'], $doc->bytes, $doc->filename, 'application/pdf', $sandbox ? 'evidence' : 'signed', $actorId, !$sandbox);
            $level = $sandbox ? 'UNKNOWN' : (in_array($validation->achievedLevel, ['SES', 'AES', 'QES'], true) && $validation->status === 'valid' ? $validation->achievedLevel : 'UNKNOWN');
            $vstatus = $sandbox ? 'not_validated' : $validation->status;
            $this->db->run(
                'INSERT INTO signature_evidence (signature_request_id, evidence_type, document_id, achieved_level, validation_status, provider_reference, detail, is_sandbox) VALUES (?,?,?,?,?,?,?,?)',
                [$rid, 'signed_document', $docId, $level, $vstatus, $r['external_transaction_id'], json_encode(['sha256' => hash('sha256', $doc->bytes), 'kind' => $doc->kind], JSON_THROW_ON_ERROR), $sandbox ? 1 : 0],
            );
            $allowed = ['certificate', 'timestamp', 'provider_receipt'];
            foreach ($evidence as $e) {
                $type = in_array($e->type, $allowed, true) ? $e->type : 'provider_receipt';
                $this->db->run(
                    'INSERT INTO signature_evidence (signature_request_id, evidence_type, achieved_level, validation_status, provider_reference, detail, is_sandbox) VALUES (?,?,?,?,?,?,?)',
                    [$rid, $type, 'UNKNOWN', 'not_validated', $e->reference, json_encode($e->detail, JSON_THROW_ON_ERROR), $sandbox ? 1 : 0],
                );
            }
            $this->db->run("UPDATE signature_requests SET status = 'signed', last_error = NULL WHERE id = ?", [$rid]);
            $this->event($rid, $sandbox ? 'signed_sandbox' : 'signed', null, $sandbox ? 'TESTBELEG – keine Signatur' : 'Vom Anbieter als signiert gemeldet');
            if (!$sandbox) {
                $this->workflow->move((int) $r['contract_id'], 'active', $actorId, 'Signatur abgeschlossen', ['signature_request_id' => $rid]);
            }
            $this->audit->log($sandbox ? 'signature.completed_sandbox' : 'signature.completed', 'success', $tenantId, $actorId, 'signature_request', $rid, [
                'provider' => $r['provider'], 'validation' => $vstatus, 'achieved_level' => $level, 'document_sha256' => hash('sha256', $doc->bytes),
            ]);
        });
        return (string) $this->db->value('SELECT status FROM signature_requests WHERE id = ?', [$rid]);
    }

    /** @param array<string,mixed> $r */
    private function closeRequest(array $r, string $status, ?int $actorId, string $detail, ?ProviderStatus $st = null): void
    {
        $rid = (int) $r['id'];
        $map = ['cancelled' => 'cancelled', 'declined' => 'declined', 'expired' => 'expired', 'failed' => 'failed'];
        $this->db->transaction(function () use ($r, $rid, $status, $actorId, $detail, $st, $map): void {
            $this->db->run('UPDATE signature_requests SET status = ? WHERE id = ? AND status IN (\'submitted\',\'partially_signed\',\'prepared\')', [$map[$status], $rid]);
            $this->event($rid, $status, $st?->detail['payload_sha256'] ?? null, mb_substr($detail, 0, 480));
            $cs = $this->db->value('SELECT status FROM contracts WHERE id = ?', [(int) $r['contract_id']]);
            if ($cs === 'signature') {
                $this->workflow->move((int) $r['contract_id'], 'approved', $actorId, 'Signaturvorgang beendet: ' . $status);
            }
            $this->audit->log('signature.' . $status, 'success', (int) $r['tenant_id'], $actorId, 'signature_request', $rid);
        });
    }

    /** @param array<string,mixed> $r */
    private function assertProvider(array $r): void
    {
        if ($r['provider'] !== $this->provider->name() || $r['mode'] !== $this->provider->mode()) {
            throw new BusinessRuleException('Der Vorgang gehört zu einem anderen Anbieter bzw. Modus als dem konfigurierten.');
        }
    }

    /** @return array<string,mixed> */
    private function load(int $id, Principal $p): array
    {
        if ($p->isExternal()) {
            throw new NotFoundException();
        }
        $r = $this->db->one('SELECT * FROM signature_requests WHERE id = ? AND tenant_id = ?', [$id, $p->tenantId]);
        if ($r === null) {
            throw new NotFoundException();
        }
        return $r;
    }

    /** @param list<SignerSpec> $signers */
    private function validateSigners(array $signers): void
    {
        if ($signers === [] || count($signers) > 10) {
            throw new ValidationException(['signers' => 'Es sind 1 bis 10 Unterzeichnende erforderlich.']);
        }
        $seen = [];
        foreach ($signers as $s) {
            $mail = strtolower(trim($s->email));
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) === false || trim($s->name) === '') {
                throw new ValidationException(['signers' => 'Name und gültige E-Mail-Adresse sind für alle Unterzeichnenden erforderlich.']);
            }
            if (isset($seen[$mail])) {
                throw new ValidationException(['signers' => 'Eine E-Mail-Adresse darf nur einmal vorkommen.']);
            }
            $seen[$mail] = true;
        }
    }

    private function event(int $rid, string $type, ?string $payloadHash, ?string $detail): void
    {
        $this->db->run('INSERT INTO signature_events (signature_request_id, event_type, provider_payload_hash, detail) VALUES (?,?,?,?)', [$rid, $type, $payloadHash, $detail]);
    }

    private function apiLog(string $op, string $idem, int $rid, ?int $http, bool $ok, ?string $err): void
    {
        $this->db->run(
            'INSERT INTO api_transactions (provider, operation, idempotency_key, related_type, related_id, http_status, outcome, error_summary) VALUES (?,?,?,?,?,?,?,?)',
            [$this->provider->name(), $op, $idem, 'signature_request', $rid, $http, $ok ? 'success' : 'failure', $err !== null ? mb_substr($err, 0, 480) : null],
        );
    }
}
