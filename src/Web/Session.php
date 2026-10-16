<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Request;

/**
 * Gehärtete Sitzung: eigenes Speicherverzeichnis (nicht das gemeinsame /tmp des Hostings), HttpOnly,
 * SameSite=Lax, Secure unter HTTPS, strikter Modus, ID-Erneuerung bei Anmeldung, Leerlauf- und Maximaldauer.
 * Die Sitzung enthält nur Kennungen und Zustand – keine Berechtigungen (diese werden pro Anfrage aus der DB gelesen).
 */
final class Session
{
    public function __construct(
        private readonly string $savePath,
        private readonly int $idleSeconds = 1800,
        private readonly int $absoluteSeconds = 43200,
    ) {
    }

    public function start(Request $r): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }
        $secure = $r->isHttps();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) $this->absoluteSeconds);
        if (is_dir($this->savePath) && is_writable($this->savePath)) {
            session_save_path($this->savePath);
        }
        session_name($secure ? '__Host-vp_sid' : 'vp_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $now = time();
        $started = (int) ($_SESSION['_started'] ?? 0);
        $last = (int) ($_SESSION['_last'] ?? 0);
        if ($started !== 0 && ($now - $last > $this->idleSeconds || $now - $started > $this->absoluteSeconds)) {
            $this->destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        if (!isset($_SESSION['_started'])) {
            $_SESSION['_started'] = $now;
        }
        $_SESSION['_last'] = $now;
    }

    public function wasExpired(): bool
    {
        return !empty($_SESSION['_expired']);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Neue Sitzungs-ID bei jeder Änderung der Vertrauensstufe (Anmeldung, MFA, Mandantenwechsel). */
    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_started'] = $_SESSION['_started'] ?? time();
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
        }
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** @param array<string,mixed> $old @param array<string,string> $errors */
    public function putOld(array $old, array $errors): void
    {
        $_SESSION['_old'] = [$old, $errors];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function takeOld(): array
    {
        $v = $_SESSION['_old'] ?? [[], []];
        unset($_SESSION['_old']);
        return $v;
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}
