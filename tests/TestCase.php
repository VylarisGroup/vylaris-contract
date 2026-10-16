<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Minimale, abhängigkeitsfreie Testbasis (PHPUnit ist im Entwicklungsnetz nicht installierbar).
 * Jeder Test läuft in einer Transaktion, die am Ende zurückgerollt wird -> keine Testdaten bleiben.
 */
abstract class TestCase
{
    protected Database $db;
    public int $assertions = 0;
    private static ?Database $shared = null;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function bootDatabase(): void
    {
        if (self::$shared === null) {
            $envFile = getenv('TEST_ENV_FILE') ?: dirname(__DIR__) . '/.env.test';
            $c = Config::fromFile($envFile);
            self::$shared = Database::connect(
                $c->get('DB_HOST', '127.0.0.1'),
                $c->int('DB_PORT', 3306),
                $c->get('DB_NAME'),
                $c->get('DB_USER'),
                $c->get('DB_PASS'),
            );
        }
        $this->db = self::$shared;
        $this->db->pdo()->beginTransaction();
    }

    public function rollbackDatabase(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    // ------------------------------------------------------------ Assertions

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== true) {
            throw new RuntimeException($msg ?: 'Erwartet true, erhalten ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== false) {
            throw new RuntimeException($msg ?: 'Erwartet false, erhalten ' . var_export($v, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    protected function assertNull(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== null) {
            throw new RuntimeException($msg ?: 'Erwartet null, erhalten ' . var_export($v, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($msg ?: "'{$needle}' nicht gefunden in '{$haystack}'");
        }
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $msg = ''): Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!($e instanceof $class)) {
                throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . "Erwartet {$class}, erhalten " . get_class($e) . ': ' . $e->getMessage());
            }
            return $e;
        }
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . "Erwartet {$class}, aber keine Exception");
    }
}
