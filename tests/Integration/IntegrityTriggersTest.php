<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\TestApp;
use Tests\TestCase;

/**
 * Prüft die DB-seitige Defense in Depth (Migration 003) direkt mit dem Anwendungskonto,
 * unabhängig von der Service-Schicht.
 */
final class IntegrityTriggersTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private int $owner;
    private int $contract;
    private int $version;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->owner = $this->app->fx->user();
        $this->contract = $this->app->fx->contract($this->tenant, $this->owner);
        $this->version = $this->app->fx->approve($this->contract);
    }

    private function assertBlocked(string $sql, array $params, string $expectedFragment): void
    {
        try {
            $this->db->execute($sql, $params);
        } catch (\PDOException $e) {
            $this->assertContains($expectedFragment, $e->getMessage());
            return;
        }
        throw new \RuntimeException("Statement wurde nicht blockiert: {$sql}");
    }

    public function testLockedVersionCannotBeUpdatedOrDeleted(): void
    {
        $this->assertBlocked('UPDATE contract_versions SET body_html = ? WHERE id = ?', ['<p>x</p>', $this->version], 'unveraenderlich');
        $this->assertBlocked('UPDATE contract_versions SET content_hash = ? WHERE id = ?', [str_repeat('0', 64), $this->version], 'unveraenderlich');
        $this->assertBlocked('UPDATE contract_versions SET state = \'draft\' WHERE id = ?', [$this->version], 'unveraenderlich');
        $this->assertBlocked('DELETE FROM contract_versions WHERE id = ?', [$this->version], 'nicht geloescht');
    }

    public function testDraftVersionRemainsEditable(): void
    {
        $draft = $this->app->fx->version($this->tenant, $this->contract, $this->owner, 2, $this->version, 'Entwurf');
        $this->assertSame(1, $this->db->execute('UPDATE contract_versions SET body_text = ? WHERE id = ?', ['geändert', $draft]));
    }

    public function testDocumentContentAndBindingAreImmutable(): void
    {
        $doc = $this->app->fx->document($this->tenant, $this->contract, $this->version, 'signed', true, $this->owner);
        $this->assertBlocked('UPDATE documents SET sha256 = ? WHERE id = ?', [str_repeat('f', 64), $doc], 'unveraenderlich');
        $this->assertBlocked('UPDATE documents SET storage_key = ? WHERE id = ?', [str_repeat('a', 40), $doc], 'unveraenderlich');
        $this->assertBlocked('UPDATE documents SET kind = \'generated\' WHERE id = ?', [$doc], 'unveraenderlich');
        $this->assertBlocked('DELETE FROM documents WHERE id = ?', [$doc], 'Loeschprozedur');
        // Soft-Delete-Markierung bleibt erlaubt
        $this->assertSame(1, $this->db->execute('UPDATE documents SET deleted_at = UTC_TIMESTAMP(6) WHERE id = ?', [$doc]));
    }

    public function testPurgeRequiresExplicitAuthorizationFlag(): void
    {
        $doc = $this->app->fx->document($this->tenant, $this->contract, $this->version, 'attachment', false, $this->owner);
        $this->db->pdo()->exec('SET @purge_authorized = 1');
        try {
            $this->assertSame(1, $this->db->execute('DELETE FROM documents WHERE id = ?', [$doc]));
        } finally {
            $this->db->pdo()->exec('SET @purge_authorized = NULL');
        }
    }

    public function testApprovalIsBoundToVersionAndDecidedApprovalCannotBeFlipped(): void
    {
        $appr = $this->app->fx->user();
        $this->db->run('INSERT INTO approvals (tenant_id, contract_id, contract_version_id, approver_user_id, version_hash, status) VALUES (?,?,?,?,?,\'approved\')', [$this->tenant, $this->contract, $this->version, $appr, str_repeat('a', 64)]);
        $id = $this->db->lastId();
        $this->assertBlocked('UPDATE approvals SET version_hash = ? WHERE id = ?', [str_repeat('b', 64), $id], 'Version gebunden');
        $this->assertBlocked("UPDATE approvals SET status = 'rejected' WHERE id = ?", [$id], 'invalidiert');
        $this->assertSame(1, $this->db->execute("UPDATE approvals SET status = 'invalidated' WHERE id = ?", [$id]), 'Invalidierung bleibt erlaubt');
    }

    public function testSignatureEvidenceIsAppendOnly(): void
    {
        $doc = $this->app->fx->document($this->tenant, $this->contract, $this->version, 'generated');
        $this->db->run(
            "INSERT INTO signature_requests (tenant_id, contract_id, contract_version_id, document_id, document_sha256, provider, mode, requested_level, signers, created_by)
             VALUES (?,?,?,?,?,'sandbox','sandbox','SES','[]',?)",
            [$this->tenant, $this->contract, $this->version, $doc, str_repeat('c', 64), $this->owner],
        );
        $req = $this->db->lastId();
        $this->db->run("INSERT INTO signature_evidence (signature_request_id, evidence_type, validation_status, is_sandbox) VALUES (?, 'provider_receipt', 'not_validated', 1)", [$req]);
        $ev = $this->db->lastId();
        $this->assertBlocked("UPDATE signature_evidence SET validation_status = 'valid' WHERE id = ?", [$ev], 'unveraenderlich');
        $this->assertBlocked('DELETE FROM signature_evidence WHERE id = ?', [$ev], 'nicht geloescht');
        $this->assertBlocked('UPDATE signature_requests SET document_sha256 = ? WHERE id = ?', [str_repeat('d', 64), $req], 'gebunden');
        $this->assertBlocked('UPDATE signature_requests SET contract_version_id = ? WHERE id = ?', [$this->version + 999, $req], 'gebunden');
    }

    public function testProvidedNotaryPdfCannotBeReplaced(): void
    {
        $doc = $this->app->fx->document($this->tenant, $this->contract, $this->version, 'generated');
        $doc2 = $this->app->fx->document($this->tenant, $this->contract, $this->version, 'generated');
        $notary = $this->app->fx->user('notary');
        $this->db->run(
            "INSERT INTO notary_cases (tenant_id, contract_id, contract_version_id, document_id, notary_user_id, notary_office, requested_by, responsible_user_id, status, provided_pdf_sha256)
             VALUES (?,?,?,?,?,'Notariat X',?,?,'provided',?)",
            [$this->tenant, $this->contract, $this->version, $doc, $notary, $this->owner, $this->owner, str_repeat('e', 64)],
        );
        $id = $this->db->lastId();
        $this->assertBlocked('UPDATE notary_cases SET document_id = ? WHERE id = ?', [$doc2, $id], 'nicht ersetzt');
        $this->assertBlocked('UPDATE notary_cases SET provided_pdf_sha256 = ? WHERE id = ?', [str_repeat('0', 64), $id], 'nicht ersetzt');
        $this->assertSame(1, $this->db->execute("UPDATE notary_cases SET status = 'in_progress' WHERE id = ?", [$id]), 'Statusfortschritt bleibt erlaubt');
    }

    public function testAuditEventsAreImmutableForApplicationAccount(): void
    {
        $this->app->audit->log('test.event', 'success', $this->tenant, $this->owner);
        $seq = (int) $this->db->value('SELECT MAX(seq) FROM audit_events');
        $this->assertBlocked("UPDATE audit_events SET outcome = 'failure' WHERE seq = ?", [$seq], 'unveraenderlich');
        $this->assertBlocked('DELETE FROM audit_events WHERE seq = ?', [$seq], 'nicht geloescht');
    }

    public function testCompositeForeignKeysPreventCrossTenantLinks(): void
    {
        $t2 = $this->app->fx->tenant();
        $foreignCp = null;
        $this->db->run("INSERT INTO counterparties (tenant_id, party_type, name) VALUES (?, 'company', 'Fremd')", [$t2]);
        $foreignCp = $this->db->lastId();
        // Vertragspartner eines anderen Mandanten darf nicht an diesen Vertrag gehängt werden.
        try {
            $this->db->run("INSERT INTO contract_parties (tenant_id, contract_id, counterparty_id, role_label) VALUES (?,?,?,'X')", [$this->tenant, $this->contract, $foreignCp]);
            throw new \RuntimeException('Fremdmandanten-Verknüpfung wurde nicht verhindert');
        } catch (\PDOException $e) {
            $this->assertContains('foreign key', strtolower($e->getMessage()));
        }
        // Abteilung eines anderen Mandanten
        $foreignDept = $this->app->fx->department($t2);
        try {
            $this->db->run('UPDATE contracts SET department_id = ? WHERE id = ?', [$foreignDept, $this->contract]);
            throw new \RuntimeException('Fremdabteilung wurde nicht verhindert');
        } catch (\PDOException $e) {
            $this->assertContains('foreign key', strtolower($e->getMessage()));
        }
    }
}
