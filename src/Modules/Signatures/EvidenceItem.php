<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Ein vom Anbieter gelieferter Nachweis (Zertifikat, Zeitstempel, Beleg). */
final class EvidenceItem
{
    /** @param array<string,mixed> $detail */
    public function __construct(
        public readonly string $type,        // certificate | timestamp | provider_receipt
        public readonly ?string $reference,
        public readonly array $detail = [],
        public readonly ?string $bytes = null,
    ) {
    }
}
