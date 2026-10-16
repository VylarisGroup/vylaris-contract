<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\DomainException;
use App\Core\ForbiddenException;
use Tests\Support\TestApp;
use Tests\TestCase;

/**
 * Prüft, dass der SQL-Sichtbarkeitsfilter (Suche, Dashboard, Zähler) exakt dasselbe ergibt wie die
 * Einzelobjekt-Prüfung AccessControl::contract() – für alle Rollen und Konstellationen.
 */
final class VisibilityTest extends TestCase
{
    private TestApp $app;
    private int $t1;
    private int $t2;
    /** @var array<string,\App\Models\Principal> */
    private array $who = [];
    /** @var list<int> */
    private array $contracts = [];
    private int $secretText;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $fx = $this->app->fx;
        $this->t1 = $fx->tenant('Mandant 1');
        $this->t2 = $fx->tenant('Mandant 2');
        $owner = $this->app->actor($this->t1, 'contract_manager');
        $dep = $fx->department($this->t1, 'Einkauf');
        $dep2 = $fx->department($this->t1, 'Verkauf');

        foreach (['tenant_admin', 'contract_manager', 'reader', 'approver'] as $r) {
            $this->who[$r] = $this->app->actor($this->t1, $r);
        }
        $this->who['reader_dep'] = $this->app->actor($this->t1, 'reader', $dep);
        $this->who['manager_dep2'] = $this->app->actor($this->t1, 'contract_manager', $dep2);
        $this->who['foreign_admin'] = $this->app->actor($this->t2, 'tenant_admin');
        $this->who['owner'] = $owner;

        $statuses = ['draft', 'review', 'approved', 'signature', 'active', 'archived', 'rejected', 'expired'];
        foreach ($statuses as $st) {
            foreach ([null, $dep, $dep2] as $d) {
                $c = $fx->contract($this->t1, $owner->userId, $d, 'draft');
                if (in_array($st, ['approved', 'signature', 'active', 'archived', 'expired'], true)) {
                    $fx->approve($c, $st);
                } else {
                    $this->db->run('UPDATE contracts SET status = ? WHERE id = ?', [$st, $c]);
                }
                $this->contracts[] = $c;
            }
        }
        $this->contracts[] = $fx->contract($this->t2, $this->who['foreign_admin']->userId);
        // ACL für den Leser und ausstehende Freigabe
        $this->db->run('INSERT INTO contract_acl (tenant_id, contract_id, user_id, can_edit, granted_by) VALUES (?,?,?,?,?)', [$this->t1, $this->contracts[0], $this->who['reader']->userId, 0, $owner->userId]);
        $vid = (int) $this->db->value('SELECT current_version_id FROM contracts WHERE id = ?', [$this->contracts[3]]);
        $this->db->run("INSERT INTO approvals (tenant_id, contract_id, contract_version_id, step_no, approver_user_id, status, version_hash) VALUES (?,?,?,?,?,'pending',?)", [$this->t1, $this->contracts[3], $vid, 1, $this->who['approver']->userId, str_repeat('a', 64)]);

        // Externe mit Mandaten verschiedener Art
        $now = '2026-06-01 00:00:00';
        $until = '2026-12-31 00:00:00';
        foreach ([['counterparty', 'external_party'], ['audit', 'auditor'], ['notary', 'notary']] as [$mt, $ut]) {
            $u = $this->app->loginUser('Korrektes-Pferd-42', $ut);
            $role = $ut;
            $fx->member($this->t1, $u['id'], $role);
            $p = $this->app->ac->principal($u['id'], null);
            $this->who['ext_' . $mt] = $p;
            $actions = ['counterparty' => ['read', 'download'], 'audit' => ['read_versions'], 'notary' => ['read']][$mt];
            $fx->mandate($this->t1, $u['id'], $mt, $this->contracts, $actions, $now, $until, $owner->userId);
            // zusätzlich abgelaufene/widerrufene Mandate dürfen nichts ändern
        }
        $u = $this->app->loginUser('Korrektes-Pferd-42', 'auditor');
        $fx->member($this->t1, $u['id'], 'auditor');
        $fx->mandate($this->t1, $u['id'], 'audit', $this->contracts, ['read_versions'], '2026-01-01 00:00:00', '2026-02-01 00:00:00', $owner->userId);
        $this->who['ext_expired'] = $this->app->ac->principal($u['id'], null);
        $u2 = $this->app->loginUser('Korrektes-Pferd-42', 'auditor');
        $fx->member($this->t1, $u2['id'], 'auditor');
        $fx->mandate($this->t1, $u2['id'], 'audit', $this->contracts, ['read_versions'], $now, $until, $owner->userId, 'revoked');
        $this->who['ext_revoked'] = $this->app->ac->principal($u2['id'], null);
        $u3 = $this->app->loginUser('Korrektes-Pferd-42', 'auditor');
        $fx->member($this->t1, $u3['id'], 'auditor');
        $fx->mandate($this->t1, $u3['id'], 'audit', [$this->contracts[2]], ['annotate'], $now, $until, $owner->userId);
        $this->who['ext_wrong_action'] = $this->app->ac->principal($u3['id'], null);
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    /** @return list<int> */
    private function viaSql(\App\Models\Principal $p): array
    {
        [$sql, $params] = $this->app->ac->visibleContractsSql($p, 'c');
        return array_map('intval', array_column($this->db->all("SELECT c.id FROM contracts c WHERE {$sql} ORDER BY c.id", $params), 'id'));
    }

    /** @return list<int> */
    private function viaObjectCheck(\App\Models\Principal $p): array
    {
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM contracts ORDER BY id'), 'id'));
        $ok = [];
        foreach ($ids as $id) {
            try {
                $this->app->ac->contract($p, $id, 'view');
                $ok[] = $id;
            } catch (DomainException) {
            }
        }
        return $ok;
    }

    public function testSqlFilterEqualsObjectCheckForEveryPrincipal(): void
    {
        foreach ($this->who as $name => $p) {
            $this->assertSame($this->viaObjectCheck($p), $this->viaSql($p), 'Abweichung bei ' . $name);
        }
        // Plausibilität: die Konstellation ist nicht trivial
        $this->assertTrue(count($this->viaSql($this->who['tenant_admin'])) > count($this->viaSql($this->who['reader'])));
        $this->assertSame([], $this->viaSql($this->who['foreign_admin']) === [] ? [] : array_diff($this->viaSql($this->who['foreign_admin']), [end($this->contracts)]));
        $this->assertSame([], $this->viaSql($this->who['ext_expired']));
        $this->assertSame([], $this->viaSql($this->who['ext_revoked']));
        $this->assertSame([], $this->viaSql($this->who['ext_wrong_action']));
        $this->assertTrue(count($this->viaSql($this->who['ext_audit'])) > 0);
    }

    public function testSearchTotalsAndRowsNeverExceedVisibility(): void
    {
        foreach ($this->who as $name => $p) {
            $vis = $this->viaSql($p);
            $res = $this->app->search->search($p, []);
            $this->assertSame(count($vis), $res['total'], 'Zähler bei ' . $name);
            foreach ($res['rows'] as $r) {
                $this->assertTrue(in_array((int) $r['id'], $vis, true), 'Zeile ausserhalb Sichtbarkeit bei ' . $name);
            }
        }
    }

    public function testFiltersCannotWidenVisibility(): void
    {
        $reader = $this->who['reader'];
        $vis = $this->viaSql($reader);
        foreach ([['status' => 'active'], ['q' => 'Testvertrag'], ['owner_user_id' => $this->who['owner']->userId], ['department_id' => '1 OR 1=1'], ['tag' => "x' OR '1'='1"]] as $f) {
            foreach ($this->app->search->search($reader, $f)['rows'] as $r) {
                $this->assertTrue(in_array((int) $r['id'], $vis, true));
            }
        }
    }

    public function testBodyTextSearchUsesApprovedVersionForExternalsOnly(): void
    {
        $fx = $this->app->fx;
        $c = $fx->contract($this->t1, $this->who['owner']->userId, null, 'draft');
        $v1 = (int) $this->db->value('SELECT current_version_id FROM contracts WHERE id = ?', [$c]);
        $this->db->run("UPDATE contract_versions SET body_text = 'Geheimklausel Alpha' WHERE id = ?", [$v1]);
        $fx->approve($c, 'approved');
        // neuer Entwurf mit anderem Text (noch nicht freigegeben)
        $v2 = $fx->version($this->t1, $c, $this->who['owner']->userId, 2, $v1, 'Entwurfswort Zebra');
        $this->db->run('UPDATE contracts SET current_version_id = ? WHERE id = ?', [$v2, $c]);
        $aud = $this->who['ext_audit'];
        $this->db->run('INSERT INTO external_mandate_contracts (mandate_id, contract_id) SELECT id, ? FROM external_mandates WHERE user_id = ? AND status = \'active\'', [$c, $aud->userId]);
        $this->assertSame(1, $this->app->search->search($aud, ['q' => 'Geheimklausel'])['total']);
        $this->assertSame(0, $this->app->search->search($aud, ['q' => 'Zebra'])['total'], 'Entwurfstext nie für Externe');
        $this->assertSame(1, $this->app->search->search($this->who['tenant_admin'], ['q' => 'Zebra'])['total']);
    }

    public function testAutocompleteOnlyReturnsVisibleContracts(): void
    {
        $fx = $this->app->fx;
        $c = $fx->contract($this->t1, $this->who['owner']->userId, null, 'draft');
        $this->db->run("UPDATE contracts SET title = 'Streng vertraulicher Sonderfall' WHERE id = ?", [$c]);
        $this->assertSame(1, count($this->app->search->suggest($this->who['tenant_admin'], 'Streng')));
        $this->assertSame([], $this->app->search->suggest($this->who['reader'], 'Streng'));
        $this->assertSame([], $this->app->search->suggest($this->who['foreign_admin'], 'Streng'));
        $this->assertSame([], $this->app->search->suggest($this->who['ext_audit'], 'Streng'));
        $this->assertSame([], $this->app->search->suggest($this->who['tenant_admin'], 'S'));
    }

    public function testDashboardCountsMatchVisibility(): void
    {
        foreach (['reader', 'approver', 'tenant_admin', 'ext_audit', 'ext_counterparty', 'foreign_admin'] as $n) {
            $o = $this->app->dashboard->overview($this->who[$n]);
            $this->assertSame(count($this->viaSql($this->who[$n])), $o['total'], $n);
        }
        $appr = $this->app->dashboard->overview($this->who['approver']);
        $this->assertSame(1, count($appr['approvals']));
        $this->assertSame([], $this->app->dashboard->overview($this->who['reader'])['approvals']);
    }

    public function testExportNeedsPermissionAndIsInjectionSafe(): void
    {
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->search->exportCsv($this->who['reader'], []));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->search->exportCsv($this->who['ext_audit'], []));
        $c = $this->app->fx->contract($this->t1, $this->who['owner']->userId, null, 'draft');
        $this->db->run("UPDATE contracts SET title = '=HYPERLINK(\"http://evil\")' WHERE id = ?", [$c]);
        $csv = $this->app->search->exportCsv($this->who['tenant_admin'], []);
        $this->assertContains("'=HYPERLINK", $csv);
        $this->assertFalse(str_contains($csv, ';=HYPERLINK'));
        $foreign = $this->app->search->exportCsv($this->who['foreign_admin'], []);
        $this->assertFalse(str_contains($foreign, 'HYPERLINK'), 'fremder Mandant exportiert nichts Fremdes');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'export.contracts_csv' AND tenant_id = ?", [$this->t1]));
    }
}
