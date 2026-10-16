<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Lesezugriffe für Sitzung/Anmeldung (keine Geschäftslogik). */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function sessionUser(int $id): ?array
    {
        return $this->db->one('SELECT id, display_name, email, user_type, is_system_admin, mfa_enabled_at FROM users WHERE id = ?', [$id]);
    }

    /**
     * Zuletzt angezeigte Programmversion. false = Spalte nicht lesbar (z. B. Migration noch nicht eingespielt): dann nichts anzeigen,
     * damit ein Update nie wegen des Änderungsverlaufs eine Seite blockiert.
     */
    public function lastSeenVersion(int $id): string|null|false
    {
        try {
            $v = $this->db->value('SELECT last_seen_version FROM users WHERE id = ?', [$id]);
            return $v === null || $v === false ? null : (string) $v;
        } catch (\Throwable) {
            return false;
        }
    }

    public function markVersionSeen(int $id, string $version): void
    {
        try {
            $this->db->run('UPDATE users SET last_seen_version = ? WHERE id = ?', [$version, $id]);
        } catch (\Throwable) {
            // Spalte fehlt: ignorieren
        }
    }

    /** @return list<array<string,mixed>> aktive Mandate des Benutzers */
    public function activeTenants(int $userId): array
    {
        return $this->db->all(
            "SELECT DISTINCT t.id, t.name FROM tenant_memberships tm JOIN tenants t ON t.id = tm.tenant_id
              WHERE tm.user_id = ? AND tm.status = 'active' AND t.status = 'active' ORDER BY t.name",
            [$userId],
        );
    }

    /** @return array<string,mixed>|null */
    public function profile(int $id): ?array
    {
        return $this->db->one('SELECT id, email, display_name, user_type, mfa_enabled_at, last_login_at, created_at FROM users WHERE id = ?', [$id]);
    }
}
