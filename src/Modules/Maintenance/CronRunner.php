<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

use App\Core\Clock;
use App\Core\Database;

/**
 * Führt Wartungsjobs mit Datenbank-Sperre aus (Plesk-Cron startet bin/cron.php minütlich/stündlich;
 * es gibt keine Dauerprozesse). Gleichzeitige Läufe desselben Jobs werden verhindert, hängende
 * Sperren laufen nach der TTL ab. Jedes Ergebnis steht in maintenance_jobs und im Cron-Log.
 */
final class CronRunner
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param callable():string $job liefert eine kurze Ergebnisbeschreibung
     * @return array{job:string,status:string,message:string}
     */
    public function run(string $name, callable $job, int $ttlSeconds = 900): array
    {
        $now = $this->clock->now();
        $this->db->run('INSERT IGNORE INTO maintenance_jobs (job_name) VALUES (?)', [$name]);
        $owner = bin2hex(random_bytes(16));
        $got = $this->db->execute(
            "UPDATE maintenance_jobs SET locked_until = ?, lock_owner = ?, last_started_at = ?, last_status = 'running'
              WHERE job_name = ? AND (locked_until IS NULL OR locked_until < ?)",
            [$now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u'), $owner, $now->format('Y-m-d H:i:s.u'), $name, $now->format('Y-m-d H:i:s.u')],
        );
        if ($got !== 1) {
            return ['job' => $name, 'status' => 'skipped', 'message' => 'läuft bereits'];
        }
        try {
            $msg = $job();
            $status = 'success';
        } catch (\Throwable $e) {
            $status = 'failure';
            $msg = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300);
        }
        $this->db->run(
            'UPDATE maintenance_jobs SET locked_until = NULL, lock_owner = NULL, last_finished_at = ?, last_status = ?, last_message = ? WHERE job_name = ? AND lock_owner = ?',
            [$this->clock->now()->format('Y-m-d H:i:s.u'), $status, mb_substr($msg, 0, 480), $name, $owner],
        );
        return ['job' => $name, 'status' => $status, 'message' => $msg];
    }

    /** @return list<array<string,mixed>> */
    public function status(): array
    {
        return $this->db->all('SELECT job_name, last_started_at, last_finished_at, last_status, last_message, locked_until FROM maintenance_jobs ORDER BY job_name');
    }
}
