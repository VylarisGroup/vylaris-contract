<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class ComplianceTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $admin;
    private \App\Models\Principal $mgr;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db, '2036-06-15 12:00:00');
        $this->tenant = $this->app->fx->tenant();
        $this->admin = $this->app->actor($this->tenant, 'tenant_admin');
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function policy(array $over = []): int
    {
        return $this->app->retention->save($this->admin, $over + [
            'name' => 'Geschäftsunterlagen', 'jurisdiction' => 'CH', 'purpose' => 'Nachweis Geschäftsvorfälle',
            'legal_basis' => 'Interne Vorgabe (juristisch zu prüfen)', 'retention_years' => 10, 'start_event' => 'contract_end', 'on_expiry' => 'delete',
        ]);
    }

    private function archivedContract(string $end = '2020-12-31'): int
    {
        $c = $this->app->fx->contract($this->tenant, $this->mgr->userId, null, 'draft');
        $this->app->fx->approve($c, 'archived');
        $this->db->run("UPDATE contracts SET end_date = ?, archived_at = '2021-01-05 00:00:00' WHERE id = ?", [$end, $c]);
        return $c;
    }

    // ---- Aufbewahrung

    public function testNoDefaultPolicyExistsAndEvaluationBlocksWithoutPolicy(): void
    {
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM retention_policies'));
        $c = $this->archivedContract();
        $e = $this->app->retention->evaluate($this->tenant, $c);
        $this->assertFalse($e['deletable']);
        $this->assertContains('Keine Aufbewahrungsregel', implode(' ', $e['blocked_by']));
    }

    public function testPolicyValidationAndReviewReset(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->policy(['legal_basis' => '']));
        $this->assertThrows(ValidationException::class, fn () => $this->policy(['retention_years' => 0]));
        $this->assertThrows(ValidationException::class, fn () => $this->policy(['purpose' => '']));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->retention->save($this->mgr, ['name' => 'x']));
        $id = $this->policy();
        $this->assertSame('to_review', (string) $this->db->value('SELECT legal_review_status FROM retention_policies WHERE id = ?', [$id]));
        $this->app->retention->markReviewed($this->admin, $id);
        $this->assertSame('reviewed', (string) $this->db->value('SELECT legal_review_status FROM retention_policies WHERE id = ?', [$id]));
        $this->policy(['retention_years' => 12]);
        $this->app->retention->save($this->admin, ['name' => 'Geschäftsunterlagen', 'jurisdiction' => 'CH', 'purpose' => 'p', 'legal_basis' => 'b', 'retention_years' => 11, 'start_event' => 'contract_end', 'on_expiry' => 'delete'], $id);
        $this->assertSame('to_review', (string) $this->db->value('SELECT legal_review_status FROM retention_policies WHERE id = ?', [$id]));
    }

    public function testUnreviewedPolicyNeverAllowsDeletion(): void
    {
        $this->policy();
        $c = $this->archivedContract();
        $e = $this->app->retention->evaluate($this->tenant, $c);
        $this->assertFalse($e['deletable']);
        $this->assertContains('nicht als geprüft', implode(' ', $e['blocked_by']));
    }

    public function testRetentionPeriodIsComputedFromStartEvent(): void
    {
        $id = $this->policy();
        $this->app->retention->markReviewed($this->admin, $id);
        $young = $this->archivedContract('2030-01-01');
        $old = $this->archivedContract('2020-12-31');
        $this->assertSame('2040-01-01', $this->app->retention->evaluate($this->tenant, $young)['retain_until']);
        $this->assertFalse($this->app->retention->evaluate($this->tenant, $young)['deletable']);
        $this->assertTrue($this->app->retention->evaluate($this->tenant, $old)['deletable']);
        $queue = $this->app->retention->reviewQueue($this->admin);
        $this->assertSame([$old], array_map(fn ($r) => (int) $r['id'], $queue));
    }

    public function testTypeSpecificPolicyWinsOverGeneral(): void
    {
        $general = $this->policy(['retention_years' => 10]);
        $nda = (int) $this->db->value("SELECT id FROM contract_types WHERE code = 'nda' AND tenant_id IS NULL");
        $this->policy(['name' => 'NDA', 'contract_type_id' => $nda, 'retention_years' => 3]);
        $c = $this->archivedContract('2020-12-31'); // Fixture-Typ = nda
        $this->assertSame('2023-12-31', $this->app->retention->evaluate($this->tenant, $c)['retain_until']);
        $this->assertTrue($general > 0);
    }

    public function testNonArchivedContractIsNeverDeletable(): void
    {
        $id = $this->policy();
        $this->app->retention->markReviewed($this->admin, $id);
        $c = $this->app->fx->contract($this->tenant, $this->mgr->userId, null, 'draft');
        $this->db->run("UPDATE contracts SET end_date = '2000-01-01' WHERE id = ?", [$c]);
        $this->assertFalse($this->app->retention->evaluate($this->tenant, $c)['deletable']);
    }

    // ---- Legal Hold

    public function testLegalHoldBlocksPurgeUntilReleased(): void
    {
        $id = $this->policy();
        $this->app->retention->markReviewed($this->admin, $id);
        $c = $this->archivedContract();
        $number = (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$c]);
        $hold = $this->app->holds->place($this->admin, $c, null, 'Laufendes Verfahren');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->purge->purge($this->admin, $c, $number));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM contracts WHERE id = ?', [$c]));
        $this->assertThrows(ValidationException::class, fn () => $this->app->holds->release($this->admin, $hold, ''));
        $this->app->holds->release($this->admin, $hold, 'Verfahren abgeschlossen');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->holds->release($this->admin, $hold, 'nochmals'));
        $this->app->purge->purge($this->admin, $c, $number);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM contracts WHERE id = ?', [$c]));
    }

    public function testHoldOnCounterpartyBlocksItsContracts(): void
    {
        $cp = $this->app->fx->counterparty($this->tenant, 'Streit AG');
        $c = $this->archivedContract();
        $this->db->run("INSERT INTO contract_parties (tenant_id, contract_id, counterparty_id, role_label) VALUES (?,?,?,'Kunde')", [$this->tenant, $c, $cp]);
        $this->app->holds->place($this->admin, null, $cp, 'Rechtsstreit');
        $this->assertTrue($this->app->holds->contractHeld($this->tenant, $c));
        $this->assertThrows(ValidationException::class, fn () => $this->app->holds->place($this->admin, $c, $cp, 'beides'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->holds->place($this->mgr, $c, null, 'x'));
        $other = $this->app->actor($this->app->fx->tenant(), 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->holds->place($other, $c, null, 'fremd'));
    }

    // ---- Löschverfahren

    public function testPurgeRemovesAllDataFilesAndKeepsAuditTrail(): void
    {
        $pid = $this->policy();
        $this->app->retention->markReviewed($this->admin, $pid);
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $doc = $this->app->documents->exportPdf($this->mgr, $c);
        $vid = (int) $this->db->value('SELECT approved_version_id FROM contracts WHERE id = ?', [$c]);
        $sd = $this->app->documents->signableDocument($c, $vid, $this->mgr->userId);
        $path = $this->app->store->absolutePath($sd);
        $this->assertTrue(is_file($path));
        $this->app->tasks->add($this->mgr, $c, 'Aufgabe', null, null);
        $this->app->comments->add($this->mgr, $c, 'Kommentar');
        $this->db->run("UPDATE contracts SET status = 'archived', archived_at = '2021-01-01 00:00:00', end_date = '2020-01-01' WHERE id = ?", [$c]);
        $number = (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$c]);

        $this->assertThrows(ValidationException::class, fn () => $this->app->purge->purge($this->admin, $c, 'FALSCH'), 'Bestätigung nötig');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->purge->purge($this->mgr, $c, $number));
        $this->app->purge->purge($this->admin, $c, $number);

        foreach (['contract_versions', 'documents', 'approvals', 'contract_comments', 'contract_tasks'] as $t) {
            $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE contract_id = ?", [$c]), $t);
        }
        $this->assertFalse(is_file($path), 'Datei gelöscht');
        $ev = $this->db->one("SELECT metadata FROM audit_events WHERE action = 'retention.purged' AND object_id = ?", [$c]);
        $this->assertTrue($ev !== null);
        $this->assertContains($number, (string) $ev['metadata']);
        $this->assertTrue($this->app->audit->verify()['ok'], 'Audit-Kette intakt');
        $this->assertTrue($doc !== null);
        // Flag darf nicht hängen bleiben: Löschschutz wirkt wieder
        $c2 = $this->app->approvedContract($this->mgr, $this->tenant);
        $this->assertThrows(\Throwable::class, fn () => $this->db->run('DELETE FROM contract_versions WHERE contract_id = ?', [$c2]));
    }

    public function testPurgeOfForeignTenantContractIsNotFound(): void
    {
        $c = $this->archivedContract();
        $other = $this->app->actor($this->app->fx->tenant(), 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->purge->purge($other, $c, 'x'));
    }

    // ---- Auskunft / Löschung

    private function party(bool $sensitive = false): int
    {
        return $this->app->fx->counterparty($this->tenant, 'Max Muster', [
            'party_type' => 'person', 'contact_email' => 'max@example.com', 'address_line' => 'Gasse 1', 'city' => 'Bern', 'country' => 'CH', 'sensitive_data' => $sensitive ? 1 : 0,
        ]);
    }

    public function testAccessReportMasksSensitiveDataWithoutPermission(): void
    {
        $cp = $this->party(true);
        $req = $this->app->privacy->open($this->admin, 'access', 'Max Muster', $cp);
        $this->assertSame('max@example.com', $this->app->privacy->accessReport($this->admin, $req)['partei']['email'], 'Admin hat sensitive.access');
        // ohne die Berechtigung (z. B. angepasste Rolle) wird maskiert
        $noSens = new \App\Models\Principal($this->admin->userId, 'internal', false, $this->tenant, ['tenant_admin'], array_values(array_diff($this->admin->permissions, ['sensitive.access'])), [], false, 'X');
        $r = $this->app->privacy->accessReport($noSens, $req);
        $this->assertFalse(isset($r['partei']['email']));
        $this->assertTrue(isset($r['partei']['hinweis']));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->privacy->open($this->mgr, 'access', 'x', null));
        $cp2 = $this->party(false);
        $r2 = $this->app->privacy->accessReport($this->admin, $this->app->privacy->open($this->admin, 'access', 'Max', $cp2));
        $this->assertSame('max@example.com', $r2['partei']['email']);
    }

    public function testErasureBlockedByRetentionAndHoldButGrantedWhenFree(): void
    {
        $cp = $this->party();
        $c = $this->app->fx->contract($this->tenant, $this->mgr->userId, null, 'active');
        $this->db->run("INSERT INTO contract_parties (tenant_id, contract_id, counterparty_id, role_label) VALUES (?,?,?,'Kunde')", [$this->tenant, $c, $cp]);
        $req = $this->app->privacy->open($this->admin, 'erasure', 'Max Muster', $cp);
        $this->assertTrue(count($this->app->privacy->erasureBlockers($this->admin, $req)) >= 1);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->privacy->decide($this->admin, $req, 'granted', 'Löschen'));
        $this->assertSame('Max Muster', (string) $this->db->value('SELECT name FROM counterparties WHERE id = ?', [$cp]));
        $this->assertThrows(ValidationException::class, fn () => $this->app->privacy->decide($this->admin, $req, 'refused', ' '), 'Begründung zwingend');
        $this->app->privacy->decide($this->admin, $req, 'partially_granted', 'Kontaktdaten entfernt, Vertragsidentifikation aus Aufbewahrungspflicht behalten');
        $row = $this->db->one('SELECT name, contact_email, address_line FROM counterparties WHERE id = ?', [$cp]);
        $this->assertSame('Max Muster', $row['name']);
        $this->assertNull($row['contact_email']);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->privacy->decide($this->admin, $req, 'refused', 'nochmals'), 'nur einmal entscheidbar');

        $free = $this->party();
        $req2 = $this->app->privacy->open($this->admin, 'erasure', 'Frei', $free);
        $this->assertSame([], $this->app->privacy->erasureBlockers($this->admin, $req2));
        $this->app->privacy->decide($this->admin, $req2, 'granted', 'Keine Aufbewahrungspflicht');
        $this->assertContains('gelöscht', (string) $this->db->value('SELECT name FROM counterparties WHERE id = ?', [$free]));
        $this->assertNull($this->db->value('SELECT contact_email FROM counterparties WHERE id = ?', [$free]));
    }

    public function testErasureRefusedUnderLegalHold(): void
    {
        $cp = $this->party();
        $this->app->holds->place($this->admin, null, $cp, 'Verfahren');
        $req = $this->app->privacy->open($this->admin, 'erasure', 'Max', $cp);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->privacy->decide($this->admin, $req, 'granted', 'x'));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->privacy->decide($this->admin, $req, 'partially_granted', 'x'));
        $this->app->privacy->decide($this->admin, $req, 'refused', 'Rechtliche Sperre');
        $this->assertSame('Max Muster', (string) $this->db->value('SELECT name FROM counterparties WHERE id = ?', [$cp]));
    }

    public function testPrivacyRequestsAreTenantScoped(): void
    {
        $req = $this->app->privacy->open($this->admin, 'access', 'Max', $this->party());
        $other = $this->app->actor($this->app->fx->tenant(), 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->privacy->accessReport($other, $req));
        $this->assertSame([], $this->app->privacy->list($other));
    }

    // ---- Parteien

    public function testCounterpartySensitiveMaskingAndIsolation(): void
    {
        $cp = $this->party(true);
        $seen = $this->app->counterparties->get($this->mgr, $cp);
        $this->assertNull($seen['contact_email']);
        $this->assertTrue($seen['masked']);
        $this->assertThrows(\App\Core\BusinessRuleException::class, fn () => $this->app->counterparties->save($this->mgr, ['name' => 'Neu', 'sensitive_data' => '1']), 'Anlegen von Hand ist nicht mehr möglich');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->counterparties->save($this->mgr, ['name' => 'Neu2'], $cp));
        $other = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->counterparties->get($other, $cp));
        $this->assertSame([], $this->app->counterparties->list($other));
        $this->assertThrows(ValidationException::class, fn () => $this->app->counterparties->save($this->admin, ['name' => '', 'contact_email' => 'kaputt'], $cp));
    }
}
