<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Web\Services;

/** Die periodischen Aufgaben; gemeinsam genutzt von bin/cron.php und der Schaltfläche »Jetzt ausführen«. */
final class CronTasks
{
    public function __construct(private readonly Services $s)
    {
    }

    /** @return array{status:string,message:string,failed:list<string>,sessions_removed:int} */
    public function run(): array
    {
        $s = $this->s;
        $steps = [
            'Fristen abgleichen' => static fn (): string => json_encode($s->deadlines->syncAllDerived(), JSON_UNESCAPED_UNICODE),
            'Fristen melden' => static fn (): string => json_encode($s->deadlines->scanAndNotify(), JSON_UNESCAPED_UNICODE),
            'Verträge ablaufen lassen' => static fn (): string => 'abgelaufen: ' . $s->deadlines->expireContracts(),
            'E-Mail-Warteschlange' => static fn (): string => json_encode($s->notifications->processEmailQueue(50), JSON_UNESCAPED_UNICODE),
            'Zeitstempel nachholen' => static fn (): string => (string) $s->signing->timestampMissing(),
            'Signaturstatus' => static fn (): string => json_encode($s->signatures->refreshOpen(50), JSON_UNESCAPED_UNICODE),
        ];
        $failed = [];
        $res = $s->cron->run('tick', static function () use ($s, $steps, &$failed): string {
            $parts = [];
            foreach ($steps as $label => $fn) {
                $r = $s->cron->run('job:' . $label, $fn, 600);
                $parts[] = $label . '=' . $r['status'];
                if ($r['status'] === 'failure') {
                    $failed[] = $label . ': ' . $r['message'];
                }
            }
            return implode('; ', $parts);
        }, 1200);

        // Abgelaufene Sitzungsdateien entfernen (absolute Sitzungsdauer 12 Std. + Reserve).
        $deleted = 0;
        foreach (glob($s->storageRoot . '/sessions/sess_*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 13 * 3600 && @unlink($f)) {
                $deleted++;
            }
        }

        // Tägliche Aufräumarbeiten (höchstens einmal pro Tag).
        $db = $s->db;
        $daily = $db->one("SELECT last_finished_at FROM maintenance_jobs WHERE job_name = 'daily' AND last_status = 'success'");
        if ($daily === null || strtotime((string) $daily['last_finished_at'] . ' UTC') < time() - 82800) {
            $s->cron->run('daily', static function () use ($s, $db): string {
                $n = $s->notifications->purgeOld(180);
                $l = $s->limiter->purgeOlderThan(86400 * 2);
                $t = $db->execute('DELETE FROM auth_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 7) . '.000000']);
                $a = $s->audit->anchor('cron-daily');
                return "Benachrichtigungen={$n}, Rate-Limit={$l}, Tokens={$t}, Anker=" . ($a['seq'] ?? '-');
            }, 1800);
        }
        return ['status' => $res['status'], 'message' => $res['message'], 'failed' => $failed, 'sessions_removed' => $deleted];
    }
}
