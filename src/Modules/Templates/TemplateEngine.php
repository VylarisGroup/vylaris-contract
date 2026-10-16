<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use App\Core\ValidationException;

/**
 * Vorlagen-Syntax (bewusst klein, keine Code-Ausführung):
 *   {{feld}}                         Platzhalter (Wert wird HTML-escaped)
 *   {{clause:CODE}}                  Klausel einfügen (rekursiv, max. Tiefe 5)
 *   {{#if feld}} … {{else}} … {{/if}}                bedingter Abschnitt
 *   {{#if feld == "wert"}} … {{/if}} / != "wert"
 *   {{#unless feld}} … {{/unless}}
 * Es gibt keine Ausdrücke, Funktionen oder Dateizugriffe.
 */
final class TemplateEngine
{
    private const MAX_DEPTH = 5;
    private const MAX_OUTPUT = 2_000_000;

    /**
     * Parst und prüft die Struktur. @return array{fields:list<string>,clauses:list<string>}
     * @throws ValidationException bei unbekannten Feldern oder unausgewogenen Blöcken
     * @param list<string> $knownFields
     */
    public static function analyze(string $body, array $knownFields): array
    {
        $nodes = self::parse($body);
        $fields = [];
        $clauses = [];
        self::collect($nodes, $fields, $clauses);
        $unknown = array_values(array_diff(array_unique($fields), $knownFields));
        if ($unknown !== []) {
            throw new ValidationException(['body' => 'Unbekannte Felder in der Vorlage: ' . implode(', ', array_map(static fn ($f) => '{{' . $f . '}}', $unknown))]);
        }
        return ['fields' => array_values(array_unique($fields)), 'clauses' => array_values(array_unique($clauses))];
    }

    /**
     * @param array<string,string> $values bereits normalisiert (boolean => '1'|'')
     * @param array<string,array{body:string,review_status:string,title:string}> $clauses
     * @return array{html:string,used_clauses:list<string>}
     */
    public static function render(string $body, array $values, array $clauses): array
    {
        $used = [];
        $html = self::renderNodes(self::parse($body), $values, $clauses, 0, [], $used);
        if (strlen($html) > self::MAX_OUTPUT) {
            throw new ValidationException(['body' => 'Das Ergebnis ist zu gross.']);
        }
        return ['html' => $html, 'used_clauses' => array_values(array_unique($used))];
    }

    // ------------------------------------------------------------------ Parser

    /** @return list<array<string,mixed>> */
    private static function parse(string $body): array
    {
        $parts = preg_split('/\{\{(.*?)\}\}/s', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new ValidationException(['body' => 'Vorlage nicht lesbar.']);
        }
        $tokens = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if ($part !== '') {
                    $tokens[] = ['text', $part];
                }
            } else {
                $tokens[] = ['tag', trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            }
        }
        $i = 0;
        $nodes = self::parseBlock($tokens, $i, null);
        return $nodes;
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     * @return list<array<string,mixed>>
     */
    private static function parseBlock(array $tokens, int &$i, ?string $open): array
    {
        $nodes = [];
        $n = count($tokens);
        while ($i < $n) {
            [$type, $v] = $tokens[$i];
            if ($type === 'text') {
                $nodes[] = ['t' => 'text', 'v' => $v];
                $i++;
                continue;
            }
            if (preg_match('/^#(if|unless)\s+(.+)$/s', $v, $m) === 1) {
                $i++;
                $cond = self::parseCond($m[2]);
                $then = self::parseBlock($tokens, $i, $m[1]);
                $else = [];
                if ($i < $n && $tokens[$i][1] === 'else') {
                    $i++;
                    $else = self::parseBlock($tokens, $i, $m[1]);
                }
                if ($i >= $n || $tokens[$i][1] !== '/' . $m[1]) {
                    throw new ValidationException(['body' => 'Ein Block ({{#' . $m[1] . '}}) wurde nicht geschlossen.']);
                }
                $i++;
                $nodes[] = ['t' => $m[1], 'cond' => $cond, 'then' => $then, 'else' => $else];
                continue;
            }
            if ($v === 'else' || $v === '/if' || $v === '/unless') {
                if ($open === null) {
                    throw new ValidationException(['body' => 'Block „{{' . $v . '}}“ ohne passenden Beginn.']);
                }
                return $nodes; // Aufrufer behandelt else / Abschluss
            }
            if (preg_match('/^clause:([A-Za-z0-9_.-]{1,64})$/', $v, $m) === 1) {
                $nodes[] = ['t' => 'clause', 'code' => $m[1]];
                $i++;
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $v) === 1) {
                $nodes[] = ['t' => 'var', 'name' => $v];
                $i++;
                continue;
            }
            throw new ValidationException(['body' => 'Ungültiger Platzhalter: {{' . mb_substr($v, 0, 40) . '}}']);
        }
        if ($open !== null) {
            throw new ValidationException(['body' => 'Ein Block ({{#' . $open . '}}) wurde nicht geschlossen.']);
        }
        return $nodes;
    }

    /** @return array{field:string,op:string,value:?string} */
    private static function parseCond(string $expr): array
    {
        $expr = trim($expr);
        if (preg_match('/^([a-z][a-z0-9_]{0,63})\s*(==|!=)\s*"([^"]{0,200})"$/u', $expr, $m) === 1) {
            return ['field' => $m[1], 'op' => $m[2], 'value' => $m[3]];
        }
        if (preg_match('/^([a-z][a-z0-9_]{0,63})$/', $expr, $m) === 1) {
            return ['field' => $m[1], 'op' => 'truthy', 'value' => null];
        }
        throw new ValidationException(['body' => 'Ungültige Bedingung: ' . mb_substr($expr, 0, 60)]);
    }

    /** @param list<array<string,mixed>> $nodes @param list<string> $fields @param list<string> $clauses */
    private static function collect(array $nodes, array &$fields, array &$clauses): void
    {
        foreach ($nodes as $n) {
            switch ($n['t']) {
                case 'var':
                    $fields[] = $n['name'];
                    break;
                case 'clause':
                    $clauses[] = $n['code'];
                    break;
                case 'if':
                case 'unless':
                    $fields[] = $n['cond']['field'];
                    self::collect($n['then'], $fields, $clauses);
                    self::collect($n['else'], $fields, $clauses);
                    break;
            }
        }
    }

    // ------------------------------------------------------------------ Rendern

    /**
     * @param list<array<string,mixed>> $nodes
     * @param array<string,string> $values
     * @param array<string,array{body:string,review_status:string,title:string}> $clauses
     * @param list<string> $trail
     * @param list<string> $used
     */
    private static function renderNodes(array $nodes, array $values, array $clauses, int $depth, array $trail, array &$used): string
    {
        $out = '';
        foreach ($nodes as $n) {
            switch ($n['t']) {
                case 'text':
                    $out .= $n['v'];
                    break;
                case 'var':
                    $out .= htmlspecialchars(self::display($values[$n['name']] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    break;
                case 'if':
                case 'unless':
                    $ok = self::test($n['cond'], $values);
                    if ($n['t'] === 'unless') {
                        $ok = !$ok;
                    }
                    $out .= self::renderNodes($ok ? $n['then'] : $n['else'], $values, $clauses, $depth, $trail, $used);
                    break;
                case 'clause':
                    $code = (string) $n['code'];
                    if ($depth >= self::MAX_DEPTH || in_array($code, $trail, true)) {
                        throw new ValidationException(['body' => 'Klauseln verschachteln sich zu tief oder zirkulär: ' . $code]);
                    }
                    if (!isset($clauses[$code])) {
                        throw new ValidationException(['body' => 'Klausel nicht gefunden oder archiviert: ' . $code]);
                    }
                    $used[] = $code;
                    $out .= self::renderNodes(self::parse($clauses[$code]['body']), $values, $clauses, $depth + 1, [...$trail, $code], $used);
                    break;
            }
            if (strlen($out) > self::MAX_OUTPUT) {
                throw new ValidationException(['body' => 'Das Ergebnis ist zu gross.']);
            }
        }
        return $out;
    }

    /** @param array{field:string,op:string,value:?string} $c @param array<string,string> $values */
    private static function test(array $c, array $values): bool
    {
        $v = trim((string) ($values[$c['field']] ?? ''));
        return match ($c['op']) {
            '==' => $v === $c['value'],
            '!=' => $v !== $c['value'],
            default => $v !== '' && !in_array(strtolower($v), ['0', 'false', 'nein', 'off'], true),
        };
    }

    private static function display(string $v): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $v === '1' ? 'ja' : $v;
    }
}
