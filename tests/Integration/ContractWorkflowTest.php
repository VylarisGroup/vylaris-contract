<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class ContractWorkflowTest extends TestCase
{
    private TestApp $app;
    private int $tenant;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
    }

    private function draftWithBody(\App\Models\Principal $mgr, string $body = '<p>Der Anbieter erbringt Leistungen.</p>'): int
    {
        $id = $this->app->contracts->create($mgr, $this->app->contractInput());
        $this->app->contracts->saveBody($mgr, $id, $body);
        return $id;
    }

    // ---- Anlegen / Nummernkreis

    public function testCreateAssignsSequentialUniqueContractNumbers(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a = $this->app->contracts->create($mgr, $this->app->contractInput());
        $b = $this->app->contracts->create($mgr, $this->app->contractInput());
        $na = (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$a]);
        $nb = (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$b]);
        $this->assertSame('V-2026-000001', $na);
        $this->assertSame('V-2026-000002', $nb);
        $this->assertSame('draft', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$a]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM contract_versions WHERE contract_id = ?', [$a]));
    }

    public function testContractNumbersAreIndependentPerTenant(): void
    {
        $t2 = $this->app->fx->tenant();
        $m1 = $this->app->actor($this->tenant, 'contract_manager');
        $m2 = $this->app->actor($t2, 'contract_manager');
        $a = $this->app->contracts->create($m1, $this->app->contractInput());
        $b = $this->app->contracts->create($m2, $this->app->contractInput());
        $this->assertSame('V-2026-000001', (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$a]));
        $this->assertSame('V-2026-000001', (string) $this->db->value('SELECT contract_number FROM contracts WHERE id = ?', [$b]));
    }

    public function testReaderCannotCreateContracts(): void
    {
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->contracts->create($reader, $this->app->contractInput()));
    }

    public function testValidationRejectsBadInput(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->app->contracts->create($mgr, $this->app->contractInput([
            'title' => '  ', 'end_date' => '2026-01-01', 'start_date' => '2026-06-01', 'value_currency' => 'chfx', 'notice_period_days' => '-5',
        ])));
        $this->assertTrue(isset($e->errors['title']) && isset($e->errors['end_date']) && isset($e->errors['value_currency']) && isset($e->errors['notice_period_days']));
    }

    public function testCannotUseForeignTenantsCounterpartyOrDepartment(): void
    {
        $t2 = $this->app->fx->tenant();
        $this->db->run("INSERT INTO counterparties (tenant_id, party_type, name) VALUES (?, 'company', 'Fremde AG')", [$t2]);
        $foreignCp = $this->db->lastId();
        $foreignDept = $this->app->fx->department($t2);
        $mgr = $this->app->actor($this->tenant, 'contract_manager');

        $e = $this->assertThrows(ValidationException::class, fn () => $this->app->contracts->create($mgr, $this->app->contractInput([
            'parties' => [['counterparty_id' => $foreignCp, 'role_label' => 'Auftragnehmer']],
        ])));
        $this->assertTrue(isset($e->errors['parties.0']));
        $e = $this->assertThrows(ValidationException::class, fn () => $this->app->contracts->create($mgr, $this->app->contractInput(['department_id' => $foreignDept])));
        $this->assertTrue(isset($e->errors['department_id']));
    }

    public function testOwnerMustBeMemberOfTenant(): void
    {
        $outsider = $this->app->fx->user();
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->app->contracts->create($mgr, $this->app->contractInput(['owner_user_id' => $outsider])));
        $this->assertTrue(isset($e->errors['owner_user_id']));
    }

    // ---- Entwurf bearbeiten, Version sperren

    public function testDraftBodyIsEditedInPlaceAndSanitized(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->app->contracts->create($mgr, $this->app->contractInput());
        $v1 = $this->app->contracts->saveBody($mgr, $id, '<p onclick="x()">A</p><script>alert(1)</script><img src=x onerror=alert(1)>');
        $v1b = $this->app->contracts->saveBody($mgr, $id, '<p>B</p>');
        $this->assertSame($v1, $v1b, 'Entwurf wird in place aktualisiert');
        $html = (string) $this->db->value('SELECT body_html FROM contract_versions WHERE id = ?', [$v1]);
        $this->assertSame('<p>B</p>', $html);
        $v1c = $this->app->contracts->saveBody($mgr, $id, '<p onclick="x()">A</p><script>alert(1)</script><img src=x onerror=alert(1)>');
        $clean = (string) $this->db->value('SELECT body_html FROM contract_versions WHERE id = ?', [$v1c]);
        $this->assertSame('<p>A</p>', $clean, 'Skripte, Attribute und Bilder werden entfernt');
    }

    public function testRequestApprovalLocksVersionAndStoresHash(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $vid = $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
        $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ?', [$vid]);
        $this->assertSame('locked', $v['state']);
        $this->assertSame(hash('sha256', (string) $v['body_html']), $v['content_hash']);
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
    }

    public function testRequestWithoutBodyIsRejected(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->app->contracts->create($mgr, $this->app->contractInput());
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->approvals->request($mgr, $id, [[$appr->userId]]));
    }

    public function testRoleWithoutApprovalRightCannotBeChosen(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $reader = $this->app->actor($this->tenant, 'reader');
        $id = $this->draftWithBody($mgr);
        $this->assertThrows(ValidationException::class, fn () => $this->app->approvals->request($mgr, $id, [[$reader->userId]]), 'Leser darf nicht freigeben');
    }

    public function testCreatorMayApproveOwnVersionAndAdminsMayBeChosen(): void
    {
        // Prüfung entfernt: kein Vier-Augen-Zwang; auch Mandantenadministration und Vertragsverantwortliche dürfen freigeben
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->app->approvals->request($mgr, $id, [[$mgr->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_id = ?', [$id]);
        $this->assertSame('approved', $this->app->approvals->decide($mgr, $ap, 'approve'));
        $admin = $this->app->actor($this->tenant, 'tenant_admin');
        $id2 = $this->draftWithBody($mgr);
        $this->app->approvals->request($mgr, $id2, [[$admin->userId]]);
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id2]));
    }

    public function testRequestAgainAfterRejectionCreatesNewDraft(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_id = ?', [$id]);
        $this->assertSame('rejected', $this->app->approvals->decide($appr, $ap, 'reject', 'Bitte Ziffer 3 prüfen'));
        $this->app->contracts->reopenForApproval($mgr, $id);
        $this->assertSame('draft', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM contract_versions WHERE contract_id = ?', [$id]));
        // Zurückgezogen geht ebenso
        $this->app->approvals->withdraw($mgr, $id, 'Zurück');
        $this->app->contracts->reopenForApproval($mgr, $id);
        $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
    }

    // ---- Freigabe-Workflow

    public function testMultiStepApprovalRunsInOrderAndEndsApproved(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $a2 = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $vid = $this->app->approvals->request($mgr, $id, [[$a1->userId], [$a2->userId]]);
        $ap1 = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ? AND step_no = 1', [$vid]);
        $ap2 = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ? AND step_no = 2', [$vid]);

        $this->assertSame(0, count($this->app->approvals->openFor($this->app->ac->principal($a2->userId, $this->tenant))), 'Schritt 2 noch nicht offen');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->approvals->decide($a2, $ap2, 'approve'), 'Reihenfolge');

        $this->assertSame('pending', $this->app->approvals->decide($a1, $ap1, 'approve'));
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $this->assertSame('approved', $this->app->approvals->decide($a2, $ap2, 'approve'));

        $c = $this->db->one('SELECT status, approved_version_id FROM contracts WHERE id = ?', [$id]);
        $this->assertSame('approved', $c['status']);
        $this->assertSame($vid, (int) $c['approved_version_id']);
    }

    public function testRejectNeedsReasonAndEndsRejected(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $a2 = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $vid = $this->app->approvals->request($mgr, $id, [[$a1->userId, $a2->userId]]);
        $ap1 = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ? AND approver_user_id = ?', [$vid, $a1->userId]);
        $ap2 = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ? AND approver_user_id = ?', [$vid, $a2->userId]);

        $this->assertThrows(ValidationException::class, fn () => $this->app->approvals->decide($a1, $ap1, 'reject', ''));
        $this->assertSame('rejected', $this->app->approvals->decide($a1, $ap1, 'reject', 'Haftungsklausel fehlt'));
        $this->assertSame('rejected', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $this->assertSame('invalidated', (string) $this->db->value('SELECT status FROM approvals WHERE id = ?', [$ap2]));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->approvals->decide($a2, $ap2, 'approve'), 'ungültige Freigabe nicht mehr entscheidbar');
    }

    public function testOnlyAssignedApproverCanDecide(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $other = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $vid = $this->app->approvals->request($mgr, $id, [[$a1->userId]]);
        $ap1 = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->approvals->decide($other, $ap1, 'approve'));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->approvals->decide($mgr, $ap1, 'approve'));
    }

    public function testDecisionBlockedWhenLockedVersionWasTamperedWithBehindTheApplication(): void
    {
        // Zustand nach einer Manipulation hinter der Anwendung (z. B. durch ein Konto mit DDL-Rechten,
        // das den Trigger entfernt hat): gesperrte Version, deren Text nicht mehr zum Hash passt.
        // Ohne DDL simuliert (DDL löst in MariaDB einen impliziten Commit aus und bräche die Test-Isolation).
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $id = $this->app->fx->contract($this->tenant, $mgr->userId, null, 'review');
        $vid = (int) $this->db->value('SELECT current_version_id FROM contracts WHERE id = ?', [$id]);
        $this->db->run("UPDATE contract_versions SET body_html = '<p>MANIPULIERT</p>', body_text = 'MANIPULIERT' WHERE id = ?", [$vid]);
        $goodHash = hash('sha256', '<p>Originaltext</p>');
        $this->db->run("UPDATE contract_versions SET state = 'locked', locked_at = UTC_TIMESTAMP(6), content_hash = ? WHERE id = ?", [$goodHash, $vid]);
        $this->db->run('INSERT INTO approvals (tenant_id, contract_id, contract_version_id, step_no, approver_user_id, version_hash) VALUES (?,?,?,1,?,?)', [$this->tenant, $id, $vid, $a1->userId, $goodHash]);
        $ap1 = $this->db->lastId();

        $e = $this->assertThrows(BusinessRuleException::class, fn () => $this->app->approvals->decide($a1, $ap1, 'approve'));
        $this->assertContains('Integritätsprüfung', $e->getMessage());
        $this->assertSame('review', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]), 'kein Statuswechsel trotz Manipulation');
        $this->assertSame('pending', (string) $this->db->value('SELECT status FROM approvals WHERE id = ?', [$ap1]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'integrity.violation'"));
    }

    // ---- Änderungen nach Freigabe

    public function testEditAfterApprovalCreatesNewVersionAndInvalidatesApproval(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr, '<p>Version eins</p>');
        $v1 = $this->app->approvals->request($mgr, $id, [[$a1->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$v1]);
        $this->app->approvals->decide($a1, $ap, 'approve');

        $v2 = $this->app->contracts->saveBody($mgr, $id, '<p>Version zwei</p>', 'Preis angepasst');
        $this->assertTrue($v2 !== $v1);
        $c = $this->db->one('SELECT status, current_version_id, approved_version_id FROM contracts WHERE id = ?', [$id]);
        $this->assertSame('draft', $c['status']);
        $this->assertSame($v2, (int) $c['current_version_id']);
        $this->assertNull($c['approved_version_id'], 'Freigabe der neuen Version existiert nicht');
        $this->assertSame('invalidated', (string) $this->db->value('SELECT status FROM approvals WHERE id = ?', [$ap]));
        $this->assertSame($v1, (int) $this->db->value('SELECT previous_version_id FROM contract_versions WHERE id = ?', [$v2]), 'Vorgängerbezug');
        $this->assertSame('<p>Version eins</p>', (string) $this->db->value('SELECT body_html FROM contract_versions WHERE id = ?', [$v1]), 'alte Version unverändert');
        $this->assertSame('locked', (string) $this->db->value('SELECT state FROM contract_versions WHERE id = ?', [$v1]));
    }

    public function testCannotEditWhileInReview(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr);
        $this->app->approvals->request($mgr, $id, [[$a1->userId]]);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->saveBody($mgr, $id, '<p>heimlich</p>'));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->update($mgr, $id, ['title' => 'Neuer Titel']));
    }

    public function testWithdrawThenReworkCreatesNewVersion(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $a1 = $this->app->actor($this->tenant, 'approver');
        $id = $this->draftWithBody($mgr, '<p>Alt</p>');
        $v1 = $this->app->approvals->request($mgr, $id, [[$a1->userId]]);
        $this->app->approvals->withdraw($mgr, $id, 'Fehler entdeckt');
        $this->assertSame('withdrawn', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $v2 = $this->app->contracts->saveBody($mgr, $id, '<p>Neu</p>');
        $this->assertTrue($v2 !== $v1);
        $this->assertSame('draft', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
    }

    public function testActiveContractRequiresAmendment(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->app->fx->approve($id, 'active');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->saveBody($mgr, $id, '<p>Überschrieben</p>'));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->update($mgr, $id, ['end_date' => '2030-01-01']));

        $amendment = $this->app->contracts->createAmendment($mgr, $id, 'amendment', []);
        $row = $this->db->one('SELECT parent_contract_id, relation_type, status FROM contracts WHERE id = ?', [$amendment]);
        $this->assertSame($id, (int) $row['parent_contract_id']);
        $this->assertSame('amendment', $row['relation_type']);
        $this->assertSame('draft', $row['status']);
    }

    public function testAmendmentNotAllowedForDraft(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->createAmendment($mgr, $id, 'amendment', []));
    }

    public function testAdministrativeMetadataStaysEditableAfterApproval(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->app->fx->approve($id, 'approved');
        $this->app->contracts->update($mgr, $id, ['tags' => ['Rahmenvertrag', ' IT ']]);
        $tags = array_column($this->db->all('SELECT t.name FROM contract_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contract_id = ? ORDER BY t.name', [$id]), 'name');
        $this->assertSame(['it', 'rahmenvertrag'], $tags);
    }

    // ---- Archivierung

    public function testArchiveRequiresReasonAndIsFinal(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->assertThrows(ValidationException::class, fn () => $this->app->contracts->archive($mgr, $id, ' '));
        $this->app->contracts->archive($mgr, $id, 'Nicht mehr benötigt');
        $this->assertSame('archived', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]));
        $this->assertTrue($this->db->value('SELECT archived_at FROM contracts WHERE id = ?', [$id]) !== null);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->contracts->update($mgr, $id, ['title' => 'X']));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->workflow->move($id, 'draft', $mgr->userId));
    }

    public function testStatusChangesAreAudited(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->draftWithBody($mgr);
        $this->app->contracts->archive($mgr, $id, 'Ende');
        $n = (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'contract.status_changed' AND object_id = ?", [$id]);
        $this->assertSame(1, $n);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->workflow->move($id, 'active', $mgr->userId));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'contract.status_change_rejected' AND object_id = ?", [$id]));
    }
}
