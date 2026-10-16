<?php

declare(strict_types=1);

namespace App\Security;

/** Ungültige oder nicht überprüfbare WebAuthn-Daten (Meldung ist für die Protokollierung gedacht, nicht für Benutzer). */
final class WebAuthnException extends \RuntimeException
{
}
