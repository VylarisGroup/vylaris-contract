<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
