<?php

declare(strict_types=1);

namespace App\Core;

/** Anmeldung/Authentifizierung fehlgeschlagen (bewusst generische Meldung). */
final class AuthException extends DomainException
{
    public function __construct(string $message = 'Anmeldung fehlgeschlagen. Bitte Eingaben prüfen.')
    {
        parent::__construct($message, 401);
    }
}
