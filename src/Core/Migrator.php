<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Versionierte SQL-Migrationen. Jede Datei wird genau einmal angewendet; die Prüfsumme
 * angewendeter Migrationen wird verglichen, nachträgliche Änderungen werden abgelehnt.
 * Statements sind in der Datei durch eine Zeile "-- @@" getrennt.
 * MySQL/MariaDB-DDL ist nicht transaktional – darum wird vor dem Lauf vollständig validiert
 * und ein Fehler bricht mit klarer Meldung ab (kein stilles Weitermachen).
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $dir,
    ) {
    }

    /** @return list<string> Dateinamen (ohne Pfad), aufsteigend */
    public function available(): array
    {
        $files = glob($this->dir . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,string> version => checksum */
    public function applied(): array
    {
        $exists = $this->db->value(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        );
        if ((int) $exists === 0) {
            return [];
        }
        $rows = $this->db->all('SELECT version, checksum FROM schema_migrations ORDER BY version');
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['version']] = (string) $r['checksum'];
        }
        return $out;
    }

    /** @return list<string> Liste der Fehler (leer = konsistent) */
    public function verify(): array
    {
        $errors = [];
        $applied = $this->applied();
        foreach ($applied as $version => $checksum) {
            $path = $this->dir . '/' . $version;
            if (!is_file($path)) {
                $errors[] = "Angewendete Migration fehlt im Dateisystem: {$version}";
            } elseif (!hash_equals($checksum, hash_file('sha256', $path) ?: '')) {
                $errors[] = "Migration wurde nach Anwendung verändert: {$version}";
            }
        }
        return $errors;
    }

    /**
     * @return array{applied:list<string>,warnings:list<string>}
     */
    public function migrate(): array
    {
        $errors = $this->verify();
        if ($errors !== []) {
            throw new RuntimeException('Migrationsprüfung fehlgeschlagen: ' . implode('; ', $errors));
        }
        $applied = $this->applied();
        $done = [];
        $warnings = [];
        foreach ($this->available() as $file) {
            if (isset($applied[$file])) {
                continue;
            }
            $path = $this->dir . '/' . $file;
            $sql = (string) file_get_contents($path);
            $statements = preg_split('/^-- @@\s*$/m', $sql) ?: [];
            foreach ($statements as $i => $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }
                try {
                    $this->db->pdo()->exec($stmt);
                } catch (Throwable $e) {
                    $isTrigger = str_starts_with($file, '003_') && stripos($stmt, 'CREATE TRIGGER') !== false;
                    if ($isTrigger && str_contains($e->getMessage(), 'TRIGGER')) {
                        $warnings[] = 'Integritäts-Trigger nicht installierbar (fehlendes TRIGGER-Recht).';
                        continue 2;
                    }
                    throw new RuntimeException(
                        sprintf('Migration %s, Statement %d fehlgeschlagen: %s', $file, $i + 1, $e->getMessage()),
                        0,
                        $e,
                    );
                }
            }
            $this->db->run(
                'INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)',
                [$file, hash('sha256', $sql)],
            );
            $done[] = $file;
        }
        return ['applied' => $done, 'warnings' => array_values(array_unique($warnings))];
    }
}
