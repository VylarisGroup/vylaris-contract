<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\TooManyRequestsException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class ConnectionTest extends TestCase
{
    private TestApp $app;
    private int $ta;
    private int $tb;
    private \App\Models\Principal $adminA;
    private \App\Models\Principal $adminB;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->ta = $this->app->fx->tenant('Alpha AG');
        $this->tb = $this->app->fx->tenant('Beta GmbH');
        $this->adminA = $this->app->actor($this->ta, 'tenant_admin');
        $this->adminB = $this->app->actor($this->tb, 'tenant_admin');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function connect(): int
    {
        $id = $this->app->connections->request($this->adminA, $this->app->connections->myCode($this->adminB));
        $this->app->connections->decide($this->adminB, $id, true);
        return $id;
    }

    public function testCodeFormatAndNormalisation(): void
    {
        $code = $this->app->connections->myCode($this->adminA);
        $this->assertSame(1, preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code));
        $this->assertSame($code, $this->app->connections->myCode($this->adminA), 'stabil');
        $this->assertSame($code, \App\Modules\Connections\ConnectionService::normalize(strtolower(str_replace('-', ' ', $code))));
        $this->assertSame('', \App\Modules\Connections\ConnectionService::normalize('zu kurz'));
        $new = $this->app->connections->regenerate($this->adminA);
        $this->assertTrue($new !== $code);
    }

    public function testRequestAcceptCreatesCounterpartiesOnBothSides(): void
    {
        $id = $this->app->connections->request($this->adminA, $this->app->connections->myCode($this->adminB), 'Hallo');
        $this->assertSame(1, count($this->app->connections->overview($this->adminB)['incoming']));
        $this->assertSame(1, count($this->app->connections->overview($this->adminA)['outgoing']));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM counterparties WHERE linked_tenant_id IS NOT NULL AND tenant_id IN (?, ?)', [$this->ta, $this->tb]), 'vor Bestätigung nichts');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->connections->decide($this->adminA, $id, true), 'Absender kann nicht selbst bestätigen');
        $this->app->connections->decide($this->adminB, $id, true);
        $this->assertSame('Beta GmbH', (string) $this->db->value('SELECT name FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]));
        $this->assertSame('Alpha AG', (string) $this->db->value('SELECT name FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->tb, $this->ta]));
        $this->assertSame(1, count($this->app->connections->overview($this->adminA)['active']));
    }

    public function testInvalidSelfAndDuplicateRequestsAreRefusedGenerically(): void
    {
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->connections->request($this->adminA, 'AAAA-BBBB-CCCC'));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->connections->request($this->adminA, $this->app->connections->myCode($this->adminA)), 'nicht mit sich selbst');
        $this->app->connections->request($this->adminA, $this->app->connections->myCode($this->adminB));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->connections->request($this->adminB, $this->app->connections->myCode($this->adminA)), 'offene Anfrage in Gegenrichtung');
    }

    public function testRateLimitOnCodeGuessing(): void
    {
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->app->connections->request($this->adminA, 'AAAA-BBBB-CC' . $i . 'D');
            } catch (BusinessRuleException) {
            }
        }
        $this->assertThrows(TooManyRequestsException::class, fn () => $this->app->connections->request($this->adminA, 'AAAA-BBBB-CCCC'));
    }

    public function testOnlyTenantAdminsMayConnect(): void
    {
        $mgr = $this->app->actor($this->ta, 'contract_manager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->connections->myCode($mgr));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->connections->request($mgr, 'AAAA-BBBB-CCCC'));
    }

    public function testRevokeAndReconnect(): void
    {
        $id = $this->connect();
        $this->app->connections->revoke($this->adminB, $id);
        $this->assertSame(0, count($this->app->connections->overview($this->adminA)['active']));
        $cp = (int) $this->db->value('SELECT id FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]);
        $this->assertFalse($this->app->connections->isActiveLink($this->ta, $cp));
        $id2 = $this->app->connections->request($this->adminB, $this->app->connections->myCode($this->adminA));
        $this->app->connections->decide($this->adminA, $id2, true);
        $this->assertTrue($this->app->connections->isActiveLink($this->ta, $cp));
    }

    public function testManualCreationBlockedAndLinkedNotEditable(): void
    {
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->counterparties->save($this->adminA, ['name' => 'Von Hand']));
        $this->connect();
        $cp = (int) $this->db->value('SELECT id FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->counterparties->save($this->adminA, ['name' => 'Umbenannt'], $cp));
    }

    public function testContractPartiesMustBeConnected(): void
    {
        $legacy = $this->app->fx->counterparty($this->ta, 'Altbestand AG');
        $in = $this->app->contractInput(['parties' => [['counterparty_id' => $legacy, 'role_label' => 'Kunde']]]);
        $this->assertThrows(\App\Core\ValidationException::class, fn () => $this->app->contracts->create($this->adminA, $in));
        $this->connect();
        $cp = (int) $this->db->value('SELECT id FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]);
        $id = $this->app->contracts->create($this->adminA, $this->app->contractInput(['parties' => [['counterparty_id' => $cp, 'role_label' => 'Kunde']]]));
        $this->assertTrue($id > 0);
    }

    public function testRenameKeepsCounterpartyNameInSync(): void
    {
        $this->connect();
        $this->db->run("UPDATE tenants SET name = 'Beta Neu AG' WHERE id = ?", [$this->tb]);
        $this->app->connections->syncName($this->tb);
        $this->assertSame('Beta Neu AG', (string) $this->db->value('SELECT name FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]));
    }

    // ---- geteilte Verträge

    /** @return array{0:int,1:int} Vertrag von A mit B als Partei, Gegenpartei-ID */
    private function sharedContract(string $status): array
    {
        $this->connect();
        $cp = (int) $this->db->value('SELECT id FROM counterparties WHERE tenant_id = ? AND linked_tenant_id = ?', [$this->ta, $this->tb]);
        $id = $this->app->contracts->create($this->adminA, $this->app->contractInput(['title' => 'Geheimer Vertrag', 'description' => 'INTERNE NOTIZ', 'parties' => [['counterparty_id' => $cp, 'role_label' => 'Kunde']]]));
        $this->app->contracts->saveBody($this->adminA, $id, '<p>Vertragstext sichtbar</p>');
        if ($status !== 'draft') {
            $appr = $this->app->actor($this->ta, 'approver');
            $vid = $this->app->approvals->request($this->adminA, $id, [[$appr->userId]]);
            $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
            $this->app->approvals->decide($appr, $ap, 'approve');
        }
        return [$id, $cp];
    }

    public function testSharedContractVisibleOnlyFromApprovalAndReadOnly(): void
    {
        [$id] = $this->sharedContract('draft');
        $this->assertSame([], $this->app->shared->list($this->adminB), 'Entwurf nie sichtbar');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->shared->get($this->adminB, $id));
        $appr = $this->app->actor($this->ta, 'approver');
        $vid = $this->app->approvals->request($this->adminA, $id, [[$appr->userId]]);
        $this->assertSame([], $this->app->shared->list($this->adminB), 'in Prüfung nicht sichtbar');
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->app->approvals->decide($appr, $ap, 'approve');
        $list = $this->app->shared->list($this->adminB);
        $this->assertSame(1, count($list));
        $c = $this->app->shared->get($this->adminB, $id);
        $this->assertContains('Vertragstext sichtbar', $c['body_html']);
        $this->assertFalse(array_key_exists('description', $c), 'interne Beschreibung nie geteilt');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'shared_contract.viewed'"));
    }

    public function testSharedAccessEndsWithConnectionAndIsolatedFromOthers(): void
    {
        [$id] = $this->sharedContract('approved');
        $this->assertSame(1, count($this->app->shared->list($this->adminB)));
        $tc = $this->app->fx->tenant('Gamma');
        $adminC = $this->app->actor($tc, 'tenant_admin');
        $this->assertSame([], $this->app->shared->list($adminC));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->shared->get($adminC, $id));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->shared->get($this->adminA, $id), 'Eigentümer nutzt die normale Ansicht');
        $conn = (int) $this->db->value('SELECT id FROM tenant_connections LIMIT 1');
        $this->app->connections->revoke($this->adminA, $conn);
        $this->assertSame([], $this->app->shared->list($this->adminB));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->shared->get($this->adminB, $id));
    }
}
