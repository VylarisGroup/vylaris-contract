<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Gemeinsames, einfaches Dokumentmodell für Export (PDF/DOCX) und Import (DOCX).
 * Block: ['type' => 'h1'|'h2'|'h3'|'p'|'li', 'text' => string, 'n' => ?int (Listennummer)]
 */
final class Blocks
{
    /** @return list<array{type:string,text:string,n:?int}> */
    public static function fromHtml(string $cleanHtml): array
    {
        $cleanHtml = trim($cleanHtml);
        if ($cleanHtml === '') {
            return [];
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__r">' . $cleanHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__r');
        $blocks = [];
        if ($root !== null) {
            self::walk($root, $blocks);
        }
        return $blocks;
    }

    /** @param list<array{type:string,text:string,n:?int}> $blocks */
    public static function toHtml(array $blocks): string
    {
        $out = '';
        $openList = false;
        foreach ($blocks as $b) {
            if ($b['type'] === 'li') {
                if (!$openList) {
                    $out .= '<ul>';
                    $openList = true;
                }
                $out .= '<li>' . self::esc($b['text']) . '</li>';
                continue;
            }
            if ($openList) {
                $out .= '</ul>';
                $openList = false;
            }
            $tag = in_array($b['type'], ['h1', 'h2', 'h3'], true) ? $b['type'] : 'p';
            $out .= "<{$tag}>" . nl2br(self::esc($b['text']), false) . "</{$tag}>";
        }
        return $out . ($openList ? '</ul>' : '');
    }

    /** @param list<array{type:string,text:string,n:?int}> $blocks */
    public static function toPlainText(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $b) {
            $lines[] = ($b['type'] === 'li' ? '• ' : '') . $b['text'];
        }
        return implode("\n\n", $lines);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param list<array{type:string,text:string,n:?int}> $blocks */
    private static function walk(DOMNode $node, array &$blocks, ?int $listN = null): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $t = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');
                if ($t !== '') {
                    $blocks[] = ['type' => 'p', 'text' => $t, 'n' => null];
                }
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            switch ($tag) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                    $blocks[] = ['type' => $tag === 'h4' ? 'h3' : $tag, 'text' => self::inlineText($child), 'n' => null];
                    break;
                case 'p':
                case 'blockquote':
                    $t = self::inlineText($child);
                    if ($t !== '') {
                        $blocks[] = ['type' => 'p', 'text' => $t, 'n' => null];
                    }
                    break;
                case 'ul':
                case 'ol':
                    $n = 0;
                    foreach ($child->childNodes as $li) {
                        if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                            $n++;
                            $blocks[] = ['type' => 'li', 'text' => self::inlineText($li), 'n' => $tag === 'ol' ? $n : null];
                        }
                    }
                    break;
                case 'table':
                    foreach ($child->getElementsByTagName('tr') as $tr) {
                        $cells = [];
                        foreach ($tr->childNodes as $td) {
                            if ($td instanceof DOMElement) {
                                $cells[] = self::inlineText($td);
                            }
                        }
                        if ($cells !== []) {
                            $blocks[] = ['type' => 'p', 'text' => implode('  |  ', $cells), 'n' => null];
                        }
                    }
                    break;
                case 'hr':
                    break;
                default:
                    self::walk($child, $blocks, $listN);
            }
        }
    }

    private static function inlineText(DOMElement $el): string
    {
        $parts = [];
        $collect = static function (DOMNode $n) use (&$collect, &$parts): void {
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $parts[] = $c->textContent;
                } elseif ($c instanceof DOMElement) {
                    if (strtolower($c->tagName) === 'br') {
                        $parts[] = "\n";
                    } else {
                        $collect($c);
                    }
                }
            }
        };
        $collect($el);
        $t = implode('', $parts);
        // Achtung: In PCRE schliesst \v den Zeilenumbruch ein – darum bewusst explizite Zeichen verwenden.
        $t = str_replace("\r", '', $t);
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/ ?\n ?/u', "\n", $t) ?? $t;
        return trim($t);
    }
}
