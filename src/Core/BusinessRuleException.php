<?php

declare(strict_types=1);

namespace App\Core;

/** Unzulässiger Statuswechsel oder Verletzung einer Geschäftsregel. */
final class BusinessRuleException extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }
}
