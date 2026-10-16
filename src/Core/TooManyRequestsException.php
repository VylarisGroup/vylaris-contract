<?php

declare(strict_types=1);

namespace App\Core;

final class TooManyRequestsException extends DomainException
{
    public function __construct(string $message = 'Zu viele Versuche. Bitte später erneut versuchen.')
    {
        parent::__construct($message, 429);
    }
}
