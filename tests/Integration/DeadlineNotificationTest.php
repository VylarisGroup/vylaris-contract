<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Mail\MailException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class DeadlineNotificationTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db, '2026-06-15 12:00:00');
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->db->run('UPDATE users SET email_verified_at = UTC_TIMESTAMP(6) WHERE id = ?', [$this->mgr->userId]);
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function activeContract(string $end = '2026-09-30', ?int $notice = 30, int $autoRenew = 0): int
    {
        $c = $this->app->fx->contract($this->tenant, $this->mgr->userId, null, 'draft');
        $this->app->fx->approve($c, 'active');
        $this->db->run('UPDATE contracts SET end_date = ?, notice_period_days = ?, auto_renew = ? WHERE id = ?', [$end, $notice, $autoRenew, $c]);
        return $c;
    }

    public function testDerivedDeadlinesAreCreatedIdempotentlyAndFollowContractChanges(): void
    {
        $c = $this->activeContract('2026-09-30', 30, 1);
        $this->assertSame(3, $this->app->deadlines->syncDerived($c));
        $this->assertSame(0, $this->app->deadlines->syncDerived($c), 'idempotent');
        $notice = $this->db->one("SELECT due_date, calc_rule FROM contract_deadlines WHERE contract_id = ? AND kind = 'notice'", [$c]);
        $this->assertSame('2026-08-31', $notice['due_date']);
        $this->assertContains('30 Tage', $notice['calc_rule']);
        $this->db->run("UPDATE contracts SET end_date = '2026-12-31', auto_renew = 0 WHERE id = ?", [$c]);
        $this->app->deadlines->syncDerived($c);
        $this->assertSame('2026-12-01', (string) $this->db->value("SELECT due_date FROM contract_deadlines WHERE contract_id = ? AND kind = 'notice' AND status = 'open'", [$c]));
        $this->assertSame('cancelled', (string) $this->db->value("SELECT status FROM contract_deadlines WHERE contract_id = ? AND kind = 'auto_renewal'", [$c]));
    }

    public function testManualDeadlineValidationAndPermissions(): void
    {
        $c = $this->activeContract();
        $this->assertThrows(ValidationException::class, fn () => $this->app->deadlines->add($this->mgr, $c, 'custom', '2026-02-30', 'x'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->deadlines->add($this->mgr, $c, 'contract_end', '2026-07-01', 'x'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->deadlines->add($this->mgr, $c, 'custom', '2026-07-01', 'x', [9999]));
        $outsider = $this->app->fx->user();
        $this->assertThrows(ValidationException::class, fn () => $this->app->deadlines->add($this->mgr, $c, 'custom', '2026-07-01', 'x', [7], $outsider));
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->deadlines->add($reader, $c, 'custom', '2026-07-01', 'x'));
        $other = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->deadlines->add($other, $c, 'custom', '2026-07-01', 'x'));
        $id = $this->app->deadlines->add($this->mgr, $c, 'payment', '2026-07-01', 'Zahlung', [7, 14]);
        $this->assertSame('[14,7]', (string) $this->db->value('SELECT remind_days_before FROM contract_deadlines WHERE id = ?', [$id]));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->deadlines->setStatus($other, $id, 'done'));
    }

    public function testRemindersFireOncePerStageAndOverdueOnce(): void
    {
        $c = $this->activeContract();
        $id = $this->app->deadlines->add($this->mgr, $c, 'custom', '2026-06-29', 'Lieferung', [14, 3], $this->mgr->userId);
        $this->assertSame(1, $this->app->deadlines->scanAndNotify()['created'], '15 Tage vorher: Stufe 14 erreicht');
        $this->assertSame(0, $this->app->deadlines->scanAndNotify()['created'], 'keine Doppelung');
        $this->app->clock->advance(86400 * 12); // 27.06. => 3 Tage
        $this->assertSame(1, $this->app->deadlines->scanAndNotify()['created']);
        $this->assertSame(0, $this->app->deadlines->scanAndNotify()['created']);
        $this->app->clock->advance(86400 * 5);  // 02.07. => überfällig
        $this->assertSame(1, $this->app->deadlines->scanAndNotify()['created']);
        $this->assertSame(0, $this->app->deadlines->scanAndNotify()['created']);
        $this->app->deadlines->setStatus($this->mgr, $id, 'done');
        $this->app->clock->advance(86400 * 5);
        $this->assertSame(0, $this->app->deadlines->scanAndNotify()['created'], 'erledigte Fristen melden nichts');
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'dashboard'", [$this->mgr->userId]));
    }

    public function testMissedRunsCatchUpWithSingleNotification(): void
    {
        $c = $this->activeContract();
        $this->app->deadlines->add($this->mgr, $c, 'custom', '2026-06-17', 'Bald', [30, 14, 7, 3], $this->mgr->userId);
        $this->app->deadlines->scanAndNotify();
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'dashboard'", [$this->mgr->userId]));
    }

    public function testNoReminderForRecipientWhoCannotSeeTheContract(): void
    {
        $c = $this->app->fx->contract($this->tenant, $this->mgr->userId, null, 'draft');
        $this->app->fx->approve($c, 'active');
        $reader = $this->app->actor($this->tenant, 'reader'); // kein Zugriff (keine ACL, kein Dept)
        $this->db->run("INSERT INTO contract_deadlines (tenant_id, contract_id, kind, due_date, label, calc_rule, remind_days_before, responsible_user_id) VALUES (?,?,?,?,?,?,?,?)", [$this->tenant, $c, 'custom', '2026-06-16', 'Geheim', 'manuell', '[7]', $reader->userId]);
        $r = $this->app->deadlines->scanAndNotify();
        $this->assertSame(0, $r['created']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$reader->userId]));
    }

    public function testExpireContractsHonoursAutoRenewal(): void
    {
        $a = $this->activeContract('2026-06-01', null, 0);
        $b = $this->activeContract('2026-06-01', null, 1);
        $this->assertSame(1, $this->app->deadlines->expireContracts());
        $this->assertSame('expired', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$a]));
        $this->assertSame('active', (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$b]));
    }

    public function testEmailQueueRetriesWithBackoffThenDies(): void
    {
        $this->app->notifications->notify($this->tenant, $this->mgr->userId, 'k1', 'Betreff', 'Text', '/vertraege/1');
        $this->app->mailer->fail = true;
        $this->assertSame(1, $this->app->notifications->processEmailQueue()['failed']);
        $this->assertSame(0, $this->app->notifications->processEmailQueue()['failed'] + $this->app->notifications->processEmailQueue()['sent'], 'noch nicht fällig');
        foreach ([300, 1800, 7200, 21600] as $i => $wait) {
            $this->app->clock->advance($wait + 1);
            $r = $this->app->notifications->processEmailQueue();
            if ($i < 3) {
                $this->assertSame(1, $r['failed']);
            } else {
                $this->assertSame(1, $r['dead']);
            }
        }
        $this->assertSame('dead', (string) $this->db->value("SELECT status FROM notifications WHERE channel = 'email' AND user_id = ?", [$this->mgr->userId]));
        $this->assertSame(0, count($this->app->mailer->sent));
    }

    public function testEmailIsSentOnceAndContainsNoContractContentButLink(): void
    {
        $this->app->notifications->notify($this->tenant, $this->mgr->userId, 'k2', 'Frist', 'Fälligkeit: 01.07.2026.', '/vertraege/5');
        $this->app->notifications->notify($this->tenant, $this->mgr->userId, 'k2', 'Frist', 'Fälligkeit: 01.07.2026.', '/vertraege/5');
        $this->assertSame(1, $this->app->notifications->processEmailQueue()['sent']);
        $this->assertSame(0, $this->app->notifications->processEmailQueue()['sent']);
        $this->assertSame(1, count($this->app->mailer->sent));
        $this->assertContains('https://test.invalid/vertraege/5', $this->app->mailer->sent[0]['body']);
    }

    public function testEmailToUnverifiedRecipientIsNotSent(): void
    {
        $u = $this->app->fx->user();
        $this->app->notifications->notify($this->tenant, $u, 'k3', 'x', 'y');
        $r = $this->app->notifications->processEmailQueue();
        $this->assertSame(1, $r['dead']);
        $this->assertSame(0, count($this->app->mailer->sent));
    }

    public function testNotificationsAreUserScoped(): void
    {
        $this->app->notifications->notify($this->tenant, $this->mgr->userId, 'k4', 'a', 'b', null, false);
        $other = $this->app->actor($this->tenant, 'contract_manager');
        $id = (int) $this->db->value("SELECT id FROM notifications WHERE user_id = ? AND channel = 'dashboard'", [$this->mgr->userId]);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->notifications->markRead($other, $id));
        $this->assertSame([], $this->app->notifications->listFor($other));
        $this->assertSame(1, $this->app->notifications->unreadCount($this->mgr));
        $this->app->notifications->markRead($this->mgr, $id);
        $this->assertSame(0, $this->app->notifications->unreadCount($this->mgr));
    }

    public function testCronRunnerLocksAndReportsFailuresWithoutCrashing(): void
    {
        $this->assertSame('success', $this->app->cron->run('t1', fn () => 'ok')['status']);
        $r = $this->app->cron->run('t2', fn () => throw new \RuntimeException('kaputt'));
        $this->assertSame('failure', $r['status']);
        $this->assertContains('kaputt', $r['message']);
        $inner = null;
        $this->app->cron->run('t3', function () use (&$inner) {
            $inner = $this->app->cron->run('t3', fn () => 'zweiter Lauf')['status'];
            return 'x';
        });
        $this->assertSame('skipped', $inner, 'Parallellauf verhindert');
        $this->db->run("UPDATE maintenance_jobs SET locked_until = '2026-06-15 11:00:00', last_status = 'running' WHERE job_name = 't3'");
        $this->assertSame('success', $this->app->cron->run('t3', fn () => 'nach Ablauf der Sperre')['status'], 'hängende Sperre läuft ab');
    }

    public function testTasksAreScoped(): void
    {
        $c = $this->activeContract();
        $tid = $this->app->tasks->add($this->mgr, $c, 'Prüfen', $this->mgr->userId, '2026-07-01');
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->tasks->add($reader, $c, 'x', null, null));
        $other = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tasks->complete($other, $tid));
        $this->app->tasks->complete($this->mgr, $tid);
        $this->assertSame('done', (string) $this->db->value('SELECT status FROM contract_tasks WHERE id = ?', [$tid]));
    }
}
