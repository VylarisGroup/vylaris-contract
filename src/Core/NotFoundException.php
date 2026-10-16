<?php

declare(strict_types=1);

namespace App\Core;

/** Existiert nicht ODER ist nicht berechtigt – bewusst nicht unterscheidbar (IDOR/Enumeration). */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Eintrag nicht gefunden.')
    {
        parent::__construct($message, 404);
    }
}
