<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Clock;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Mail\MailException;
use App\Mail\Mailer;
use App\Models\Principal;

/**
 * Benachrichtigungen: Dashboard-Hinweise und E-Mail über eine Warteschlange mit Wiederholungen.
 * Deduplizierung über (Benutzer, Kanal, dedupe_key). E-Mails enthalten bewusst nur Vertragsnummer,
 * Anlass und Link – keine Vertragsinhalte.
 */
final class NotificationService
{
    /** Wartezeiten in Sekunden nach dem n-ten Fehlversuch; danach "dead". */
    private const BACKOFF = [300, 1800, 7200, 21600];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Mailer $mailer,
        private readonly string $baseUrl,
    ) {
    }

    /** @return bool true, wenn neu angelegt (nicht dedupliziert) */
    public function notify(int $tenantId, int $userId, string $dedupeKey, string $subject, string $body, ?string $linkPath = null, bool $email = true): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $key = hash('sha256', $dedupeKey);
        $subject = mb_substr($subject, 0, 255);
        $body = mb_substr($body, 0, 2000);
        $created = $this->db->execute(
            "INSERT IGNORE INTO notifications (tenant_id, user_id, dedupe_key, channel, subject, body, link_path, status, sent_at, created_at) VALUES (?,?,?,?,?,?,?,'sent',?,?)",
            [$tenantId, $userId, $key, 'dashboard', $subject, $body, $linkPath, $now, $now],
        ) === 1;
        if ($email) {
            $this->db->execute(
                "INSERT IGNORE INTO notifications (tenant_id, user_id, dedupe_key, channel, subject, body, link_path, status, next_attempt_at, created_at) VALUES (?,?,?,?,?,?,?,'pending',?,?)",
                [$tenantId, $userId, $key, 'email', $subject, $body, $linkPath, $now, $now],
            );
        }
        return $created;
    }

    /** Versendet fällige E-Mails. @return array{sent:int,failed:int,dead:int} */
    public function processEmailQueue(int $limit = 50): array
    {
        $now = $this->clock->now();
        $rows = $this->db->all(
            "SELECT n.*, u.email, u.display_name, u.status AS user_status, u.email_verified_at FROM notifications n JOIN users u ON u.id = n.user_id
              WHERE n.channel = 'email' AND n.status = 'pending' AND n.next_attempt_at <= ? ORDER BY n.id LIMIT " . (int) $limit,
            [$now->format('Y-m-d H:i:s.u')],
        );
        $out = ['sent' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($rows as $n) {
            if ($n['user_status'] !== 'active' || $n['email_verified_at'] === null) {
                $this->db->run("UPDATE notifications SET status = 'dead', last_error = 'Empfänger inaktiv oder nicht verifiziert' WHERE id = ?", [(int) $n['id']]);
                $out['dead']++;
                continue;
            }
            $text = $n['body'] . ($n['link_path'] ? "\n\n" . rtrim($this->baseUrl, '/') . $n['link_path'] : '');
            try {
                $this->mailer->send((string) $n['email'], (string) $n['display_name'], (string) $n['subject'], $text);
                $this->db->run("UPDATE notifications SET status = 'sent', sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?", [$now->format('Y-m-d H:i:s.u'), (int) $n['id']]);
                $out['sent']++;
            } catch (MailException $e) {
                $attempts = (int) $n['attempts'] + 1;
                if ($attempts > count(self::BACKOFF)) {
                    $this->db->run("UPDATE notifications SET status = 'dead', attempts = ?, last_error = ? WHERE id = ?", [$attempts, mb_substr($e->getMessage(), 0, 480), (int) $n['id']]);
                    $out['dead']++;
                } else {
                    $next = $now->modify('+' . self::BACKOFF[$attempts - 1] . ' seconds')->format('Y-m-d H:i:s.u');
                    $this->db->run('UPDATE notifications SET attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?', [$attempts, $next, mb_substr($e->getMessage(), 0, 480), (int) $n['id']]);
                    $out['failed']++;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(Principal $p, int $limit = 30): array
    {
        return $this->db->all(
            "SELECT id, subject, body, link_path, read_at, created_at FROM notifications WHERE user_id = ? AND channel = 'dashboard' ORDER BY id DESC LIMIT " . (int) $limit,
            [$p->userId],
        );
    }

    public function unreadCount(Principal $p): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'dashboard' AND read_at IS NULL", [$p->userId]);
    }

    public function markRead(Principal $p, int $id): void
    {
        $n = $this->db->execute("UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ? AND user_id = ? AND channel = 'dashboard'", [$this->clock->now()->format('Y-m-d H:i:s.u'), $id, $p->userId]);
        if ($n === 0 && !$this->db->value("SELECT id FROM notifications WHERE id = ? AND user_id = ? AND channel = 'dashboard'", [$id, $p->userId])) {
            throw new NotFoundException();
        }
    }

    public function purgeOld(int $keepDays = 180): int
    {
        $cut = $this->clock->now()->modify("-{$keepDays} days")->format('Y-m-d H:i:s.u');
        return $this->db->execute("DELETE FROM notifications WHERE created_at < ? AND status IN ('sent','dead')", [$cut]);
    }
}
