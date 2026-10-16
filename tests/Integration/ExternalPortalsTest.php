<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\DomainException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Modules\Documents\PlainPdfRenderer;
use Tests\Support\TestApp;
use Tests\TestCase;

final class ExternalPortalsTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;
    private \App\Models\Principal $admin;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->admin = $this->app->actor($this->tenant, 'tenant_admin');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function mandate(string $type, string $email, array $contracts, array $actions, ?string $until = null, ?\App\Models\Principal $by = null): int
    {
        return $this->app->mandates->create($by ?? $this->admin, $type, $email, 'Extern Person', 'Org AG', $contracts, $actions, '2026-06-15', $until ?? '2026-09-01', $this->mgr->userId);
    }

    private function visible(\App\Models\Principal $p, int $contractId, string $action = 'view'): bool
    {
        try {
            $this->app->ac->contract($p, $contractId, $action);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    // ---- Mandate

    public function testCreateMandateInvitesNewExternalUserWithCorrectTypeAndRole(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $this->mandate('audit', 'revisor@example.com', [$c], ['read_versions']);
        $u = $this->db->one("SELECT * FROM users WHERE email = 'revisor@example.com'");
        $this->assertSame('auditor', $u['user_type']);
        $this->assertSame('pending', $u['status']);
        $this->assertSame(1, count($this->app->mailer->sent));
        $this->assertSame('auditor', (string) $this->db->value('SELECT r.code FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.user_id = ?', [$u['id']]));
    }

    public function testMandateValidation(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $bad = [
            fn () => $this->mandate('hacker', 'a@example.com', [$c], ['read']),
            fn () => $this->mandate('audit', 'kaputt', [$c], ['read_versions']),
            fn () => $this->mandate('audit', 'a@example.com', [$c], ['sign']),
            fn () => $this->mandate('audit', 'a@example.com', [$c], []),
            fn () => $this->mandate('audit', 'a@example.com', [], ['read_versions']),
            fn () => $this->mandate('audit', 'a@example.com', [$c], ['read_versions'], '2026-06-10'),
            fn () => $this->mandate('audit', 'a@example.com', [$c], ['read_versions'], '2027-12-31'),
        ];
        foreach ($bad as $i => $f) {
            $this->assertThrows(ValidationException::class, $f, 'Fall ' . $i);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM external_mandates'));
    }

    public function testMandateRequiresPermissionAndOwnContracts(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $this->assertThrows(ForbiddenException::class, fn () => $this->mandate('audit', 'a@example.com', [$c], ['read_versions'], null, $this->mgr));
        $t2 = $this->app->fx->tenant();
        $admin2 = $this->app->actor($t2, 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->mandates->create($admin2, 'audit', 'a@example.com', 'X', null, [$c], ['read_versions'], '2026-06-15', '2026-09-01'));
    }

    public function testCannotGrantMandateToInternalAccountOrWrongType(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $internal = $this->app->loginUser();
        $this->assertThrows(BusinessRuleException::class, fn () => $this->mandate('audit', $internal['email'], [$c], ['read_versions']));
        $notary = $this->app->externalUser($this->tenant, 'notary');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->mandate('audit', $notary['email'], [$c], ['read_versions']));
    }

    public function testRevocationAndExpiryTakeEffectImmediately(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $aud = $this->app->externalUser($this->tenant, 'auditor');
        $m = $this->mandate('audit', $aud['email'], [$c], ['read_versions', 'read_history']);
        $this->assertTrue($this->visible($aud['principal'], $c));
        $this->app->mandates->revoke($this->admin, $m, 'Prüfung beendet');
        $this->assertFalse($this->visible($aud['principal'], $c));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->mandates->revoke($this->admin, $m, 'nochmals'));

        $m2 = $this->mandate('audit', $aud['email'], [$c], ['read_versions']);
        $this->assertTrue($this->visible($aud['principal'], $c));
        $this->app->clock->advance(86400 * 80);
        $this->assertFalse($this->visible($aud['principal'], $c), 'abgelaufen');
        $this->assertThrows(ValidationException::class, fn () => $this->app->mandates->extend($this->admin, $m2, '2026-06-20'), 'Verlängerung in die Vergangenheit');
    }

    public function testMandateDoesNotLeakDraftsOrOtherContracts(): void
    {
        $approved = $this->app->approvedContract($this->mgr, $this->tenant);
        $draft = $this->app->contracts->create($this->mgr, $this->app->contractInput());
        $other = $this->app->approvedContract($this->mgr, $this->tenant);
        $aud = $this->app->externalUser($this->tenant, 'auditor');
        $this->mandate('audit', $aud['email'], [$approved, $draft], ['read_versions']);
        $this->assertTrue($this->visible($aud['principal'], $approved));
        $this->assertFalse($this->visible($aud['principal'], $draft), 'Entwurf nie sichtbar');
        $this->assertFalse($this->visible($aud['principal'], $other), 'nicht zugewiesen');
    }

    public function testMandateAdminListIsTenantScoped(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $this->mandate('audit', 'x@example.com', [$c], ['read_versions']);
        $this->assertSame(1, count($this->app->mandates->list($this->admin)));
        $this->assertSame([], $this->app->mandates->list($this->app->actor($this->app->fx->tenant(), 'tenant_admin')));
    }

    // ---- Revisionsportal

    public function testAuditorSeesOnlyAllowedParts(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $a1 = $this->app->externalUser($this->tenant, 'auditor');
        $a2 = $this->app->externalUser($this->tenant, 'auditor');
        $this->mandate('audit', $a1['email'], [$c], ['read_versions']);
        $this->mandate('audit', $a2['email'], [$c], ['read_versions', 'read_history', 'read_evidence', 'annotate', 'export_report']);
        $v = $this->app->auditor->view($a1['principal'], $c);
        $this->assertSame(64, strlen((string) $v['version']['content_hash']));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->auditor->history($a1['principal'], $c));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->auditor->evidence($a1['principal'], $c));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->auditor->annotate($a1['principal'], $c, 'x'));
        $this->assertTrue(count($this->app->auditor->history($a2['principal'], $c)) >= 2);
        $ev = $this->app->auditor->evidence($a2['principal'], $c);
        $this->assertSame(1, count($ev['approvals']));
        $this->assertTrue($ev['chain']['ok']);
        $this->assertFalse(isset($ev['chain']['checked']), 'keine mandantenübergreifenden Zähler');
    }

    public function testAuditNotesAreInvisibleToCounterpartyAndOtherAuditors(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $a = $this->app->externalUser($this->tenant, 'auditor');
        $a2 = $this->app->externalUser($this->tenant, 'auditor');
        $cp = $this->app->externalUser($this->tenant, 'external_party');
        $this->mandate('audit', $a['email'], [$c], ['read_versions', 'annotate']);
        $this->mandate('audit', $a2['email'], [$c], ['read_versions']);
        $this->mandate('counterparty', $cp['email'], [$c], ['read', 'comment']);
        $this->app->auditor->annotate($a['principal'], $c, 'Vertrauliche Feststellung');
        $this->app->comments->add($this->mgr, $c, 'Interner Kommentar', 'internal');
        $this->app->comments->add($this->mgr, $c, 'Geteilter Kommentar', 'shared');
        $this->app->comments->add($cp['principal'], $c, 'Frage der Gegenpartei');
        $texts = fn (array $rows) => implode('|', array_column($rows, 'body'));
        $this->assertSame('Geteilter Kommentar|Frage der Gegenpartei', $texts($this->app->comments->list($cp['principal'], $c)));
        $this->assertSame('Vertrauliche Feststellung', $texts($this->app->comments->list($a['principal'], $c)));
        $this->assertSame('', $texts($this->app->comments->list($a2['principal'], $c)));
        $this->assertFalse(str_contains($texts($this->app->comments->list($this->mgr, $c)), 'Vertrauliche'), 'Manager ohne audit.view sieht keine Prüfvermerke');
        $this->assertContains('Vertrauliche', $texts($this->app->comments->list($this->admin, $c)));
        $this->assertContains('Interner Kommentar', $texts($this->app->comments->list($this->mgr, $c)));
    }

    public function testCounterpartyCannotWriteInternalCommentsAndNeedsMandateAction(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $cp = $this->app->externalUser($this->tenant, 'external_party');
        $this->mandate('counterparty', $cp['email'], [$c], ['read']);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->comments->add($cp['principal'], $c, 'x'), 'Mandat ohne comment');
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->comments->add($reader, $c, 'intern', 'internal'));
    }

    public function testAuditReportExportIsPdfLoggedAndRestricted(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $a = $this->app->externalUser($this->tenant, 'auditor');
        $b = $this->app->externalUser($this->tenant, 'auditor');
        $this->mandate('audit', $a['email'], [$c], ['read_versions', 'export_report', 'annotate']);
        $this->mandate('audit', $b['email'], [$c], ['read_versions']);
        $this->app->auditor->annotate($a['principal'], $c, 'Notiz A');
        $r = $this->app->auditor->exportReport($a['principal'], [$c]);
        $this->assertTrue(str_starts_with($r['bytes'], '%PDF-'));
        $this->assertSame(hash('sha256', $r['bytes']), $r['sha256']);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->auditor->exportReport($b['principal'], [$c]));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->auditor->exportReport($this->admin, [$c]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auditor.report_exported' AND actor_user_id = ?", [$a['id']]));
    }

    public function testAuditorAccessIsLogged(): void
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $a = $this->app->externalUser($this->tenant, 'auditor');
        $this->mandate('audit', $a['email'], [$c], ['read_versions']);
        $this->app->auditor->view($a['principal'], $c);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auditor.viewed_contract' AND actor_user_id = ?", [$a['id']]));
    }

    // ---- Notariat

    private function pdfFile(string $text = 'Beglaubigung'): string
    {
        return $this->app->tmpFile((new PlainPdfRenderer())->render('Rückläufer', [['type' => 'p', 'text' => $text, 'n' => null]]));
    }

    /** @return array{c:int,n:array<string,mixed>,case:int} */
    private function notaryCase(): array
    {
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $n = $this->app->externalUser($this->tenant, 'notary');
        $case = $this->app->notary->create($this->mgr, $c, $n['id'], 'Notariat Muster', $this->mgr->userId);
        return ['c' => $c, 'n' => $n, 'case' => $case];
    }

    public function testNotaryHasNoAccessBeforeProvision(): void
    {
        $x = $this->notaryCase();
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notary->get($x['n']['principal'], $x['case']));
        $this->assertSame([], $this->app->notary->listFor($x['n']['principal']));
        $this->assertFalse($this->visible($x['n']['principal'], $x['c']), 'ohne Mandat kein Zugriff');
    }

    public function testProvideBindsPdfHashAndGrantsAccess(): void
    {
        $x = $this->notaryCase();
        $this->app->notary->provide($this->mgr, $x['case']);
        $case = $this->db->one('SELECT * FROM notary_cases WHERE id = ?', [$x['case']]);
        $doc = $this->db->one('SELECT * FROM documents WHERE id = ?', [(int) $case['document_id']]);
        $this->assertSame('provided', $case['status']);
        $this->assertSame($doc['sha256'], $case['provided_pdf_sha256']);
        $this->assertTrue($this->visible($x['n']['principal'], $x['c'], 'download'));
        $this->assertSame(1, count($this->app->notary->listFor($x['n']['principal'])));
        $resp = $this->app->store->download($x['n']['principal'], (int) $doc['id']);
        $this->assertSame(200, $resp->status);
    }

    public function testProvidedPdfCannotBeReplaced(): void
    {
        $x = $this->notaryCase();
        $this->app->notary->provide($this->mgr, $x['case']);
        $this->assertThrows(\Throwable::class, fn () => $this->db->run("UPDATE notary_cases SET provided_pdf_sha256 = ? WHERE id = ?", [str_repeat('b', 64), $x['case']]));
        $this->assertThrows(\Throwable::class, fn () => $this->db->run('UPDATE notary_cases SET document_id = ? WHERE id = ?', [$this->app->fx->document($this->tenant, $x['c'], null), $x['case']]));
    }

    public function testNotaryWorkflowWithInquiryReturnAndCompletion(): void
    {
        $x = $this->notaryCase();
        $np = $x['n']['principal'];
        $this->app->notary->provide($this->mgr, $x['case']);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->notaryUpdate($np, $x['case'], 'certified'), 'Sprung nicht erlaubt');
        $this->app->notary->notaryUpdate($np, $x['case'], 'in_progress');
        $this->assertThrows(ValidationException::class, fn () => $this->app->notary->notaryUpdate($np, $x['case'], 'inquiry'), 'Rückfrage braucht Text');
        $this->app->notary->notaryUpdate($np, $x['case'], 'inquiry', 'Welche Fassung gilt?');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->notaryUpdate($np, $x['case'], 'certified'));
        $this->app->notary->internalUpdate($this->mgr, $x['case'], 'in_progress', 'Fassung v1');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->notaryUpdate($np, $x['case'], 'certified'), 'ohne Rückläufer nicht beglaubigt');
        $returnId = $this->app->notary->uploadReturn($np, $x['case'], $this->pdfFile(), 'beglaubigt.pdf');
        $this->app->notary->notaryUpdate($np, $x['case'], 'certified');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->notaryUpdate($np, $x['case'], 'rejected', 'nein'), 'certified ist final für das Notariat');
        $this->app->notary->internalUpdate($this->mgr, $x['case'], 'completed');
        $this->assertSame('completed', (string) $this->db->value('SELECT status FROM notary_cases WHERE id = ?', [$x['case']]));
        // Original-PDF unverändert, Rückläufer getrennt
        $case = $this->db->one('SELECT * FROM notary_cases WHERE id = ?', [$x['case']]);
        $this->assertTrue((int) $case['document_id'] !== $returnId);
        $this->app->store->verifyIntegrity((array) $this->db->one('SELECT * FROM documents WHERE id = ?', [(int) $case['document_id']]));
        $this->assertSame('notary_return', (string) $this->db->value('SELECT kind FROM documents WHERE id = ?', [$returnId]));
        $this->assertSame(1, count($this->app->notary->get($this->mgr, $x['case'])['returns']));
    }

    public function testNotaryReturnMustBeValidPdf(): void
    {
        $x = $this->notaryCase();
        $this->app->notary->provide($this->mgr, $x['case']);
        $this->app->notary->notaryUpdate($x['n']['principal'], $x['case'], 'in_progress');
        $this->assertThrows(ValidationException::class, fn () => $this->app->notary->uploadReturn($x['n']['principal'], $x['case'], $this->app->tmpFile('<?php echo 1;'), 'x.pdf'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->notary->uploadReturn($x['n']['principal'], $x['case'], $this->app->tmpFile('MZ'), 'x.exe'));
    }

    public function testOtherNotaryAndCounterpartyCannotTouchCase(): void
    {
        $x = $this->notaryCase();
        $this->app->notary->provide($this->mgr, $x['case']);
        $other = $this->app->externalUser($this->tenant, 'notary');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notary->get($other['principal'], $x['case']));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notary->notaryUpdate($other['principal'], $x['case'], 'in_progress'));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notary->uploadReturn($other['principal'], $x['case'], $this->pdfFile(), 'a.pdf'));
        $doc = (int) $this->db->value('SELECT document_id FROM notary_cases WHERE id = ?', [$x['case']]);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->store->download($other['principal'], $doc));
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->notary->provide($reader, $x['case']));
        $foreign = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notary->provide($foreign, $x['case']));
    }

    public function testCaseNeedsApprovedContractAndRealNotary(): void
    {
        $draft = $this->app->contracts->create($this->mgr, $this->app->contractInput());
        $n = $this->app->externalUser($this->tenant, 'notary');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->create($this->mgr, $draft, $n['id'], 'N'));
        $c = $this->app->approvedContract($this->mgr, $this->tenant);
        $this->assertThrows(ValidationException::class, fn () => $this->app->notary->create($this->mgr, $c, $this->mgr->userId, 'N'), 'interner Benutzer ist kein Notariat');
    }

    public function testProvisionFailsIfApprovedVersionChanged(): void
    {
        $x = $this->notaryCase();
        $old = (int) $this->db->value('SELECT approved_version_id FROM contracts WHERE id = ?', [$x['c']]);
        $new = $this->app->fx->version($this->tenant, $x['c'], $this->mgr->userId, 9, $old, 'Andere Fassung');
        $this->db->run('UPDATE contracts SET approved_version_id = ? WHERE id = ?', [$new, $x['c']]);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->notary->provide($this->mgr, $x['case']));
    }
}
