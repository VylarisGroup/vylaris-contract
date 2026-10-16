<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Der authentifizierte Handelnde samt Rechten im aktiven Mandanten.
 * Wird ausschliesslich serverseitig aus der Datenbank aufgebaut, nie aus Client-Daten.
 */
final class Principal
{
    /**
     * @param list<string> $roleCodes
     * @param list<string> $permissions
     * @param list<int> $departmentIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userType,
        public readonly bool $isSystemAdmin,
        public readonly ?int $tenantId,
        public readonly array $roleCodes,
        public readonly array $permissions,
        public readonly array $departmentIds,
        public readonly bool $mfaVerified,
        public readonly string $displayName = '',
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roleCodes, true);
    }

    public function isExternal(): bool
    {
        return $this->userType !== 'internal';
    }
}
