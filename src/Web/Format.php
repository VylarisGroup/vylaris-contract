<?php

declare(strict_types=1);

namespace App\Web;

/** Darstellungshilfen (Schweizer Konventionen, Zeitzone Europe/Zurich). */
final class Format
{
    public const STATUS = [
        'draft' => 'Entwurf', 'review' => 'In Prüfung', 'approved' => 'Freigegeben', 'signature' => 'In Unterzeichnung',
        'active' => 'Aktiv', 'archived' => 'Archiviert', 'rejected' => 'Abgelehnt', 'withdrawn' => 'Zurückgezogen',
        'expired' => 'Abgelaufen', 'cancelled' => 'Storniert',
    ];

    public static function status(string $s): string
    {
        return self::STATUS[$s] ?? $s;
    }

    public static function dt(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '–';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i');
        } catch (\Exception) {
            return '–';
        }
    }

    public static function date(?string $d): string
    {
        if ($d === null || $d === '') {
            return '–';
        }
        try {
            return (new \DateTimeImmutable(substr($d, 0, 10)))->format('d.m.Y');
        } catch (\Exception) {
            return '–';
        }
    }

    public static function money(mixed $amount, ?string $cur): string
    {
        return $amount === null || $amount === '' ? '–' : ($cur ?? 'CHF') . ' ' . number_format((float) $amount, 2, '.', "’");
    }

    public static function bytes(int $n): string
    {
        return $n >= 1048576 ? number_format($n / 1048576, 1, '.', '') . ' MB' : max(1, (int) round($n / 1024)) . ' kB';
    }
}
