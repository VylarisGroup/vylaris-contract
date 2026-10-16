<?php

declare(strict_types=1);

namespace App\Modules\Maintenance;

/**
 * Liest docs/CHANGELOG.md und liefert die Einträge, die eine Person seit ihrer zuletzt gesehenen Version noch nicht kennt.
 * Reiner Text: Der Inhalt wird in der Oberfläche immer escaped ausgegeben, es wird kein HTML aus der Datei übernommen.
 */
final class ChangelogService
{
    public const SECTIONS = ['Neu' => 'bx-star', 'Verbessert' => 'bx-up-arrow-circle', 'Behoben' => 'bx-wrench', 'Wichtig' => 'bx-error'];

    public function __construct(private readonly string $file)
    {
    }

    /** @return list<array{version:string,date:string,intro:string,sections:array<string,list<string>>}> neueste zuerst */
    public function entries(): array
    {
        if (!is_file($this->file) || ($raw = file_get_contents($this->file)) === false) {
            return [];
        }
        $out = [];
        $cur = null;
        $sec = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^##\s+(\d+\.\d+\.\d+)\s*(?:[–-]\s*(\S.*))?$/u', $line, $m) === 1) {
                if ($cur !== null) {
                    $out[] = $cur;
                }
                $cur = ['version' => $m[1], 'date' => trim($m[2] ?? ''), 'intro' => '', 'sections' => []];
                $sec = null;
            } elseif ($cur === null) {
                continue;
            } elseif (preg_match('/^###\s+(.+?)\s*$/u', $line, $m) === 1) {
                $sec = $m[1];
                $cur['sections'][$sec] ??= [];
            } elseif (preg_match('/^[-*]\s+(.+)$/u', $line, $m) === 1 && $sec !== null) {
                $cur['sections'][$sec][] = trim($m[1]);
            } elseif (trim($line) !== '' && $sec === null) {
                $cur['intro'] = trim($cur['intro'] . ' ' . trim($line));
            }
        }
        if ($cur !== null) {
            $out[] = $cur;
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $out;
    }

    /**
     * Einträge nach $seen bis einschliesslich $current. Ohne bekannte Version ($seen leer) nur der Eintrag der aktuellen Version.
     *
     * @return list<array{version:string,date:string,intro:string,sections:array<string,list<string>>}>
     */
    public function since(?string $seen, string $current): array
    {
        $res = [];
        foreach ($this->entries() as $e) {
            if (version_compare($e['version'], $current, '>')) {
                continue;
            }
            if ($seen === null || $seen === '' || preg_match('/^\d+\.\d+\.\d+/', $seen) !== 1) {
                if ($e['version'] === $current) {
                    $res[] = $e;
                }
                continue;
            }
            if (version_compare($e['version'], $seen, '>')) {
                $res[] = $e;
            }
        }
        return $res;
    }

    public static function isUnseen(?string $seen, string $current): bool
    {
        return $current !== '0.0.0' && ($seen === null || $seen === '' || version_compare($seen, $current, '<'));
    }
}
