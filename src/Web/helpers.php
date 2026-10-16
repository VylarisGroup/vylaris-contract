<?php

declare(strict_types=1);

use App\Web\Format;
use App\Web\View;

if (!function_exists('e')) {
    /** HTML-Escaping für jede Ausgabe in Templates. */
    function e(mixed $v): string
    {
        return View::e($v);
    }

    function fstatus(string $s): string
    {
        return Format::status($s);
    }

    function fdt(?string $s): string
    {
        return Format::dt($s);
    }

    function fdate(?string $s): string
    {
        return Format::date($s);
    }

    function fmoney(mixed $a, ?string $c): string
    {
        return Format::money($a, $c);
    }

    /** Farbklasse eines Status-Badges (immer zusammen mit Text, nie nur Farbe). */
    function status_class(string $s): string
    {
        return match ($s) {
            'active' => 'badge-ok',
            'approved' => 'badge-info',
            'review', 'signature' => 'badge-warn',
            'rejected', 'cancelled', 'expired', 'withdrawn' => 'badge-bad',
            default => 'badge-neutral',
        };
    }
}
