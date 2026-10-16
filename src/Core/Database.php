<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Dünner PDO-Wrapper. Ausschliesslich Prepared Statements, echte (nicht emulierte)
 * Prepares, Exceptions bei Fehlern, UTC als Verbindungs-Zeitzone.
 */
final class Database
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params
     *  @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params
     *  @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<int|string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Transaktion mit Savepoint-Unterstützung für verschachtelte Aufrufe.
     * Bei Exception wird zurückgerollt und die Exception weitergereicht.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        // "Äusserste" Transaktion = keine laufende; sonst Savepoint (auch wenn ein Aufrufer, z. B.
        // ein Test, die Transaktion extern geöffnet hat).
        $outer = !$this->pdo->inTransaction();
        $savepoint = 'sp_' . (++$this->depth);
        try {
            if ($outer) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec("SAVEPOINT {$savepoint}");
            }
            $result = $fn();
            if ($outer) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return $result;
        } catch (Throwable $e) {
            try {
                if ($outer) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                }
            } catch (PDOException) {
                // Ursprüngliche Exception hat Vorrang.
            }
            throw $e;
        }
    }
}
