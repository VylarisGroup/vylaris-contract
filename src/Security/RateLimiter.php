<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;
use App\Core\TooManyRequestsException;

/** Fensterbasierte Begrenzung über die Tabelle login_attempts (funktioniert ohne APCu/Redis). */
final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public static function bucket(string $scope, string $subject): string
    {
        return hash('sha256', $scope . '|' . strtolower($subject));
    }

    public function hit(string $bucket): void
    {
        $this->db->run('INSERT INTO login_attempts (bucket, attempted_at) VALUES (?, ?)', [$bucket, $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }

    public function count(string $bucket, int $windowSeconds): int
    {
        $since = $this->clock->now()->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s.u');
        return (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE bucket = ? AND attempted_at >= ?', [$bucket, $since]);
    }

    public function assertBelow(string $bucket, int $max, int $windowSeconds): void
    {
        if ($this->count($bucket, $windowSeconds) >= $max) {
            throw new TooManyRequestsException();
        }
    }

    public function clear(string $bucket): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE bucket = ?', [$bucket]);
    }

    public function purgeOlderThan(int $seconds): int
    {
        $cut = $this->clock->now()->modify("-{$seconds} seconds")->format('Y-m-d H:i:s.u');
        return $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [$cut]);
    }
}
