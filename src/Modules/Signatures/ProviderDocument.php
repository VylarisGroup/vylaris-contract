<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

final class ProviderDocument
{
    /** kind: "signed" (vom Anbieter signiertes Original) oder "sandbox_receipt" (Testbeleg, KEINE Signatur) */
    public function __construct(
        public readonly string $bytes,
        public readonly string $filename,
        public readonly string $kind = 'signed',
    ) {
    }
}
