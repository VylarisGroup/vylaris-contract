<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Konfiguration aus .env (ausserhalb des Webroots) und Umgebungsvariablen.
 * Geheimnisse werden nie geloggt oder in Fehlermeldungen ausgegeben.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values = [];

    public function __construct(array $values = [])
    {
        $this->values = array_map('strval', $values);
    }

    public static function fromFile(string $envPath): self
    {
        if (!is_file($envPath) || !is_readable($envPath)) {
            throw new RuntimeException('Konfigurationsdatei fehlt oder ist nicht lesbar.');
        }
        $values = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1) {
                $values[$key] = $val;
            }
        }
        return new self($values);
    }

    public function get(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Konfigurationswert fehlt: {$key}");
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) || getenv($key) !== false;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        return in_array(strtolower($this->get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        return $this->has($key) ? (int) $this->get($key) : $default;
    }
}
