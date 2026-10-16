<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Basis für fachlich erwartete Fehler mit sicherer, nutzerlesbarer Meldung. */
class DomainException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
