<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

use RuntimeException;

/** Fehler der Signaturschnittstelle. "retryable" = vorübergehend (Netz, 429, 5xx), sonst endgültig. */
final class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
