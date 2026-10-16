<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Ergebnis einer Signaturprüfung. status: valid | invalid | indeterminate | not_validated; level: SES|AES|QES|UNKNOWN */
final class ValidationResult
{
    /** @param array<string,mixed> $detail */
    public function __construct(
        public readonly string $status,
        public readonly string $achievedLevel = 'UNKNOWN',
        public readonly array $detail = [],
    ) {
    }
}
