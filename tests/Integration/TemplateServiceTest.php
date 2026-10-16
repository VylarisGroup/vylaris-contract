<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class TemplateServiceTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;
    private int $typeId;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->typeId = (int) $this->db->value("SELECT id FROM contract_types WHERE code = 'service' AND tenant_id IS NULL");
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function schema(): array
    {
        return [
            ['code' => 'kunde', 'label' => 'Kunde', 'type' => 'text', 'required' => true],
            ['code' => 'start', 'label' => 'Beginn', 'type' => 'date', 'required' => true],
            ['code' => 'premium', 'label' => 'Premium', 'type' => 'boolean'],
            ['code' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['Kauf', 'Miete']],
        ];
    }

    private function tpl(string $body = '<p>Vertrag mit {{kunde}} ab {{start}}.</p>{{#if premium}}<p>Premium</p>{{/if}}'): int
    {
        return $this->app->templates->create($this->mgr, 'Dienstleistung', $this->typeId, 'CH', $body, $this->schema());
    }

    private function approve(int $tid): void
    {
        $this->app->templates->requestReview($this->mgr, $tid);
        $rev = $this->app->actor($this->tenant, 'tenant_admin');
        $this->app->templates->decideReview($rev, $tid, true);
    }

    public function testCreateStartsUnreviewedWithVersionOne(): void
    {
        $id = $this->tpl();
        $t = $this->app->templates->get($this->mgr, $id);
        $this->assertSame('unreviewed', $t['review_status']);
        $this->assertSame(1, (int) $t['current']['version_no']);
    }

    public function testReaderCanViewButNotManage(): void
    {
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->templates->create($reader, 'X', $this->typeId, 'CH', '<p>a</p>', []));
    }

    public function testUnknownPlaceholderAndBadSchemaRejected(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->app->templates->create($this->mgr, 'X', $this->typeId, 'CH', '<p>{{unbekannt}}</p>', $this->schema()));
        $this->assertThrows(ValidationException::class, fn () => $this->app->templates->create($this->mgr, 'X', $this->typeId, 'CH', '<p>a</p>', [['code' => 'Bad Code', 'label' => 'x', 'type' => 'text']]));
        $this->assertThrows(ValidationException::class, fn () => $this->app->templates->create($this->mgr, 'X', $this->typeId, 'XXXXX', '<p>a</p>', []));
    }

    public function testBodyIsSanitized(): void
    {
        $id = $this->tpl('<p>{{kunde}}</p><script>alert(1)</script>{{start}}');
        $body = (string) $this->app->templates->get($this->mgr, $id)['current']['body'];
        $this->assertFalse(str_contains($body, '<script'));
    }

    public function testFourEyesForTemplateReview(): void
    {
        $id = $this->tpl();
        $this->app->templates->requestReview($this->mgr, $id);
        $admin = $this->app->actor($this->tenant, 'tenant_admin');
        $this->db->run('UPDATE template_versions SET created_by = ? WHERE template_id = ?', [$admin->userId, $id]);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->templates->decideReview($admin, $id, true), 'Ersteller:in darf nicht selbst freigeben');
        $other = $this->app->actor($this->tenant, 'tenant_admin');
        $this->app->templates->decideReview($other, $id, true);
        $this->assertSame('approved', $this->app->templates->get($this->mgr, $id)['review_status']);
    }

    public function testManagerCannotApprove(): void
    {
        $id = $this->tpl();
        $this->app->templates->requestReview($this->mgr, $id);
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->templates->decideReview($this->mgr, $id, true));
    }

    public function testNewVersionKeepsOldOneAndResetsReview(): void
    {
        $id = $this->tpl();
        $this->approve($id);
        $this->app->templates->addVersion($this->mgr, $id, '<p>Neu {{kunde}} {{start}}</p>', $this->schema());
        $t = $this->app->templates->get($this->mgr, $id);
        $this->assertSame('unreviewed', $t['review_status']);
        $this->assertSame(2, count($t['versions']));
        $this->assertSame('approved', $t['versions'][1]['review_status'], 'alte Version bleibt freigegeben');
    }

    public function testInstantiateRequiresApprovalUnlessConfirmedAndRecordsWarning(): void
    {
        $id = $this->tpl();
        $in = $this->app->contractInput();
        $vals = ['kunde' => 'Muster AG', 'start' => '2026-07-01', 'premium' => '1'];
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->templates->instantiate($this->mgr, $id, $in, $vals));
        $r = $this->app->templates->instantiate($this->mgr, $id, $in, $vals, true);
        $this->assertTrue(count($r['warnings']) >= 1);
        $v = $this->db->one('SELECT body_html, template_version_id, form_answers FROM contract_versions WHERE contract_id = ?', [$r['contract_id']]);
        $this->assertContains('Muster AG', $v['body_html']);
        $this->assertContains('01.07.2026', $v['body_html']);
        $this->assertContains('Premium', $v['body_html']);
        $this->assertTrue($v['template_version_id'] !== null);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'template.instantiated' AND object_id = ?", [$r['contract_id']]));
    }

    public function testInstantiateFromApprovedTemplateHasNoWarningAndValidatesValues(): void
    {
        $id = $this->tpl();
        $this->approve($id);
        $in = $this->app->contractInput();
        $e = $this->assertThrows(ValidationException::class, fn () => $this->app->templates->instantiate($this->mgr, $id, $in, ['kunde' => '', 'start' => '31.12.2026']));
        $this->assertTrue(isset($e->errors['field_kunde']) && isset($e->errors['field_start']));
        $r = $this->app->templates->instantiate($this->mgr, $id, $in, ['kunde' => 'A', 'start' => '2026-07-01']);
        $this->assertSame([], $r['warnings']);
    }

    public function testClausesAreIncludedAndFlaggedWhenUnreviewed(): void
    {
        $cid = $this->app->templates->saveClause($this->mgr, 'HAFT', 'Haftung', '<p>Haftung beschränkt.</p>');
        $id = $this->tpl('<p>{{kunde}} {{start}}</p>{{clause:HAFT}}');
        $this->approve($id);
        $r = $this->app->templates->instantiate($this->mgr, $id, $this->app->contractInput(), ['kunde' => 'A', 'start' => '2026-07-01']);
        $this->assertTrue(count($r['warnings']) === 1 && str_contains($r['warnings'][0], 'HAFT'));
        $this->assertContains('Haftung beschränkt', (string) $this->db->value('SELECT body_html FROM contract_versions WHERE contract_id = ?', [$r['contract_id']]));
        $admin = $this->app->actor($this->tenant, 'tenant_admin');
        $this->app->templates->setClauseReview($admin, $cid, 'approved');
        $r2 = $this->app->templates->instantiate($this->mgr, $id, $this->app->contractInput(), ['kunde' => 'A', 'start' => '2026-07-01']);
        $this->assertSame([], $r2['warnings']);
    }

    public function testEditingClauseResetsItsReviewAndMissingClauseRejectedOnSave(): void
    {
        $cid = $this->app->templates->saveClause($this->mgr, 'K1', 'Klausel', '<p>a</p>');
        $admin = $this->app->actor($this->tenant, 'tenant_admin');
        $this->app->templates->setClauseReview($admin, $cid, 'approved');
        $this->app->templates->saveClause($this->mgr, 'K1', 'Klausel', '<p>b</p>', $cid);
        $this->assertSame('unreviewed', (string) $this->db->value('SELECT review_status FROM template_clauses WHERE id = ?', [$cid]));
        $this->assertThrows(ValidationException::class, fn () => $this->tpl('<p>{{kunde}}{{start}}</p>{{clause:NIX}}'));
    }

    public function testTenantIsolation(): void
    {
        $id = $this->tpl();
        $this->app->templates->saveClause($this->mgr, 'EIGEN', 'x', '<p>x</p>');
        $other = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->templates->get($other, $id));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->templates->requestReview($other, $id));
        $this->assertSame([], $this->app->templates->list($other));
        $this->assertThrows(ValidationException::class, fn () => $this->app->templates->create($other, 'Y', $this->typeId, 'CH', '<p>{{kunde}}</p>{{clause:EIGEN}}', [['code' => 'kunde', 'label' => 'K', 'type' => 'text']]), 'fremde Klausel unbekannt');
        $this->assertSame([], $this->app->templates->listClauses($other));
    }

    public function testArchivedTemplateCannotBeUsed(): void
    {
        $id = $this->tpl();
        $this->app->templates->archive($this->mgr, $id);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->templates->instantiate($this->mgr, $id, $this->app->contractInput(), ['kunde' => 'A', 'start' => '2026-07-01'], true));
    }
}
