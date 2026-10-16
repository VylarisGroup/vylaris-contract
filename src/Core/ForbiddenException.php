<?php

declare(strict_types=1);

namespace App\Core;

final class ForbiddenException extends DomainException
{
    public function __construct(string $message = 'Keine Berechtigung für diese Aktion.')
    {
        parent::__construct($message, 403);
    }
}
