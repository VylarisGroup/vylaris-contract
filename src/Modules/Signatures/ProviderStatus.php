<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Normalisierter Anbieterstatus. state: open | signed | declined | expired | cancelled | failed | unknown
 * "unknown" bedeutet: Der Anbieter meldete einen Wert, den wir nicht sicher zuordnen können –
 * der Aufrufer darf daraus NIE einen Erfolg ableiten.
 */
final class ProviderStatus
{
    /** @param array<string,mixed> $detail datensparsame Rohangaben (für Beleg) */
    public function __construct(
        public readonly string $state,
        public readonly ?string $signedDocumentRef = null,
        public readonly array $detail = [],
        public readonly ?string $rawValue = null,
    ) {
    }
}
