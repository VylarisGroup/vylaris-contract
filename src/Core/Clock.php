<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/** Zeitquelle, damit Ablauf von Mandaten, Sessions und Fristen deterministisch testbar ist. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
