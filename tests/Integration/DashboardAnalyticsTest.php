<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\TestApp;
use Tests\TestCase;

final class DashboardAnalyticsTest extends TestCase
{
    private TestApp $app;
    private int $tenant;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function active(\App\Models\Principal $mgr, string $end, string $amount, string $cur = 'CHF'): int
    {
        $id = $this->app->contracts->create($mgr, $this->app->contractInput(['start_date' => '2020-01-01', 'end_date' => $end, 'value_amount' => $amount, 'value_currency' => $cur]));
        $this->db->run("UPDATE contracts SET status = 'active' WHERE id = ?", [$id]);
        return $id;
    }

    public function testBucketsValuesAndVisibility(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $today = $this->app->clock->now();
        $d = static fn (int $n): string => $today->modify(sprintf("%+d days", $n))->format("Y-m-d");
        $this->active($mgr, $d(10), '1000.00');
        $this->active($mgr, $d(45), '2000.50');
        $this->active($mgr, $d(80), '500.00', 'EUR');
        $this->active($mgr, $d(400), '10.00');
        $this->active($mgr, $d(-3), '1.00');

        // fremder Mandant darf nie einfliessen
        $other = $this->app->fx->tenant('Fremd');
        $om = $this->app->actor($other, 'contract_manager');
        $this->active($om, $d(5), '999999.00');

        $this->db->run('UPDATE contracts SET created_at = ? WHERE tenant_id = ?', [$today->format('Y-m-d H:i:s'), $this->tenant]);
        $a = $this->app->dashboard->analytics($mgr);
        $this->assertSame(['overdue' => 1, 'd30' => 1, 'd60' => 1, 'd90' => 1], $a['expiry']);
        $sums = [];
        foreach ($a['values'] as $v) {
            $sums[$v['currency']] = ($sums[$v['currency']] ?? 0) + (float) $v['total'];
        }
        $this->assertSame(['CHF' => 3011.5, 'EUR' => 500.0], $sums);
        $this->assertSame(6, count($a['created']));
        $this->assertSame(5, $a['created'][5]['n'], 'aktueller Monat');

        $reader = $this->app->actor($this->tenant, 'reader'); // ohne Abteilungszugehörigkeit sieht der Leser keine Verträge – die Auswertung folgt derselben Sichtbarkeit
        $this->assertSame([], $this->app->dashboard->analytics($reader)['values']);
    }

    public function testApprovalDurationAndOpenSignatures(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->app->contracts->create($mgr, $this->app->contractInput());
        $this->app->contracts->saveBody($mgr, $id, '<p>x</p>');
        $vid = $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
        $a1 = $this->app->dashboard->analytics($mgr);
        $this->assertSame(1, $a1['approval']['pending']);
        $this->assertSame(0, $a1['approval']['decided']);
        $this->assertTrue($a1['approval']['avg_days'] === null);

        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->app->clock->advance(3 * 86400);
        $this->app->approvals->decide($appr, $ap, 'approve');
        $a2 = $this->app->dashboard->analytics($mgr);
        $this->assertSame(1, $a2['approval']['decided']);
        $this->assertTrue($a2['approval']['avg_days'] !== null);
        $this->assertSame(0, $a2['approval']['pending']);
    }

    public function testCsvIsGuardedAndSafe(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $id = $this->app->contracts->create($mgr, $this->app->contractInput());
        $this->db->run("UPDATE contract_types SET name = name WHERE 1 = 0");
        $csv = $this->app->dashboard->analyticsCsv($mgr);
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"));
        $this->assertTrue(str_contains($csv, 'Vertragswerte aktiver Verträge'));
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(\App\Core\ForbiddenException::class, fn () => $this->app->dashboard->analyticsCsv($reader));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'export.dashboard_csv'"));
    }
}
