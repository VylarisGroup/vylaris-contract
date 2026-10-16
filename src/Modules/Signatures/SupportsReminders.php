<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Optional: Anbieter, bei denen die Plattform selbst Einladungen verschickt und erneut senden kann. */
interface SupportsReminders
{
    /** Verschickt allen aktuell Einladenen einen neuen Link (der alte wird ungültig). @return int Anzahl versendeter Mails */
    public function remind(string $externalId): int;
}
