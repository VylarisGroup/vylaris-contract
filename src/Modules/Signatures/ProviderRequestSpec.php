<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Anbieterneutrale Beschreibung einer Signaturanfrage. */
final class ProviderRequestSpec
{
    /** @param list<SignerSpec> $signers */
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $pdfBytes,
        public readonly string $pdfSha256,
        public readonly array $signers,
        public readonly string $level,
        public readonly string $idempotencyKey,
        public readonly string $reference,
    ) {
    }
}
