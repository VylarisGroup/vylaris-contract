<?php

declare(strict_types=1);

namespace App\Core;

final class ValidationException extends DomainException
{
    /** @param array<string,string> $errors Feld => Meldung */
    public function __construct(public readonly array $errors, string $message = 'Bitte Eingaben prüfen.')
    {
        parent::__construct($message, 422);
    }
}
