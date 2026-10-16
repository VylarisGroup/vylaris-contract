<?php

declare(strict_types=1);

namespace App\Modules\Documents;

/**
 * Abhängigkeitsfreier PDF-Schreiber für Vertragstext (PDF 1.4, Standardschriften Helvetica /
 * Helvetica-Bold mit WinAnsi-Kodierung, Flate-komprimierte Inhaltsströme).
 *
 * BEWUSSTE GRENZEN: Reiner Textsatz (Überschriften, Absätze, Listen). Keine Bilder, keine
 * Tabellenlayouts, keine eingebetteten Schriften; Zeichen ausserhalb von Windows-1252 werden als
 * "?" ausgegeben. Für aufwendigeres Layout kann ein anderer Renderer hinter derselben Methode
 * eingesetzt werden. Das erzeugte PDF ist NICHT signiert und kein PDF/A.
 *
 * Die Erzeugung ist deterministisch (festes Erstellungsdatum, keine Zufallswerte), damit dieselbe
 * Version immer dieselben Bytes ergibt.
 */
final class PlainPdfRenderer
{
    /** Helvetica-Zeichenbreiten (1/1000 em) für ASCII 32..126. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const PAGE_W = 595.0;
    private const PAGE_H = 842.0;
    private const MARGIN_X = 62.0;
    private const MARGIN_TOP = 72.0;
    private const MARGIN_BOTTOM = 64.0;

    /**
     * @param list<array{type:string,text:string,n:?int}> $blocks
     * @param array{contract_number?:string,version_no?:int,content_hash?:string,created_at?:string,test_watermark?:bool} $meta
     */
    public function render(string $title, array $blocks, array $meta = []): string
    {
        $pages = $this->layout($title, $blocks);
        $total = count($pages);
        $footerLeft = trim(($meta['contract_number'] ?? '') . (isset($meta['version_no']) ? '  ·  Version ' . $meta['version_no'] : ''));
        $hash = isset($meta['content_hash']) ? 'Textstand SHA-256: ' . substr((string) $meta['content_hash'], 0, 16) . '…' : '';

        $objects = [];   // 1-basiert
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        foreach ($pages as $i => $ops) {
            $footer = $this->footerOps($footerLeft, $hash, $i + 1, $total, !empty($meta['test_watermark']));
            $stream = gzcompress(implode("\n", $ops) . "\n" . $footer, 6);
            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = "<< /Length " . strlen((string) $stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::n(self::PAGE_W), self::n(self::PAGE_H), $contentId);
            $kids[] = $pageId . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoId = $next++;
        $date = $this->pdfDate($meta['created_at'] ?? '2000-01-01 00:00:00');
        $objects[$infoId] = '<< /Title (' . $this->pdfString($title) . ') /Producer (Vertragsplattform) /CreationDate (' . $date . ') >>';

        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $maxId = max(array_keys($objects));
        $out .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    /**
     * Seiteninhalte (unkomprimierte Inhaltsströme inkl. Fusszeile) für Anhangseiten, die an ein bestehendes PDF angehängt werden
     * (siehe PdfAppender). Verwendet dieselben Schriften /F1 (Helvetica) und /F2 (Helvetica-Bold) wie render().
     *
     * @param list<array{type:string,text:string,n:?int}> $blocks
     * @return list<string>
     */
    public function appendixContents(string $title, array $blocks, string $footerLeft): array
    {
        $pages = $this->layout($title, $blocks);
        $total = count($pages);
        $out = [];
        foreach ($pages as $i => $ops) {
            $out[] = implode("\n", $ops) . "\n" . $this->footerOps($footerLeft, '', $i + 1, $total, false);
        }
        return $out;
    }

    /** @return list<list<string>> Seiten, jeweils Liste von Zeichenoperatoren */
    private function layout(string $title, array $blocks): array
    {
        $pages = [[]];
        $y = self::PAGE_H - self::MARGIN_TOP;
        $maxW = self::PAGE_W - 2 * self::MARGIN_X;
        $newPage = function () use (&$pages, &$y): void {
            $pages[] = [];
            $y = self::PAGE_H - self::MARGIN_TOP;
        };
        $emit = function (string $text, string $font, float $size, float $x, float $leading, float $spaceBefore = 0.0) use (&$pages, &$y, $newPage): void {
            $y -= $spaceBefore;
            if ($y - $leading < self::MARGIN_BOTTOM) {
                $newPage();
            }
            $y -= $leading;
            $pages[count($pages) - 1][] = sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET', $font, self::n($size), self::n($x), self::n($y), $this->pdfString($text));
        };

        foreach ($this->wrap($title, 17.0, true, $maxW) as $line) {
            $emit($line, 'F2', 17.0, self::MARGIN_X, 22.0);
        }
        $y -= 10.0;

        foreach ($blocks as $b) {
            $type = $b['type'];
            if (in_array($type, ['h1', 'h2', 'h3'], true)) {
                $size = ['h1' => 14.0, 'h2' => 12.5, 'h3' => 11.5][$type];
                foreach ($this->wrap($b['text'], $size, true, $maxW) as $k => $line) {
                    $emit($line, 'F2', $size, self::MARGIN_X, $size + 4.0, $k === 0 ? 8.0 : 0.0);
                }
                continue;
            }
            $indent = $type === 'li' ? 18.0 : 0.0;
            $prefix = $type === 'li' ? ($b['n'] !== null ? $b['n'] . '.' : '•') : '';
            foreach (explode("\n", $b['text']) as $para => $paraText) {
                $lines = $this->wrap($paraText, 10.5, false, $maxW - $indent);
                foreach ($lines as $k => $line) {
                    $first = $k === 0 && $para === 0;
                    $before = $first ? 5.0 : 0.0;
                    if ($first && $prefix !== '') {
                        // Aufzählungszeichen setzen, dann auf dieselbe Zeile zurück und Text setzen.
                        $emit($prefix, 'F1', 10.5, self::MARGIN_X + 2.0, 14.5, $before);
                        $y += 14.5;
                        $before = 0.0;
                    }
                    $emit($line, 'F1', 10.5, self::MARGIN_X + $indent, 14.5, $before);
                }
            }
        }
        return $pages;
    }

    private function footerOps(string $left, string $hash, int $page, int $total, bool $watermark): string
    {
        $ops = [];
        $ops[] = sprintf('0.6 g BT /F1 8 Tf %s 40 Td (%s) Tj ET', self::n(self::MARGIN_X), $this->pdfString($left));
        if ($hash !== '') {
            $ops[] = sprintf('BT /F1 8 Tf %s 29 Td (%s) Tj ET', self::n(self::MARGIN_X), $this->pdfString($hash));
        }
        $label = 'Seite ' . $page . ' / ' . $total;
        $w = $this->width($label, 8.0, false);
        $ops[] = sprintf('BT /F1 8 Tf %s 40 Td (%s) Tj ET', self::n(self::PAGE_W - self::MARGIN_X - $w), $this->pdfString($label));
        if ($watermark) {
            $ops[] = '0.85 g BT /F2 54 Tf 0.9 0.4 -0.4 0.9 120 330 Tm (TESTDOKUMENT) Tj ET 0 g';
        }
        return implode("\n", $ops);
    }

    /** @return list<string> */
    private function wrap(string $text, float $size, bool $bold, float $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            // Überlange Wörter hart trennen
            while ($this->width($word, $size, $bold) > $maxWidth) {
                $cut = 1;
                while ($cut < mb_strlen($word) && $this->width(mb_substr($word, 0, $cut + 1), $size, $bold) <= $maxWidth) {
                    $cut++;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->width($try, $size, $bold) <= $maxWidth) {
                $line = $try;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines === [] ? [''] : $lines;
    }

    private function width(string $text, float $size, bool $bold): float
    {
        $w = 0.0;
        foreach (mb_str_split($text) as $ch) {
            $o = mb_ord($ch);
            $unit = ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : 556;
            $w += $unit * ($bold ? 1.06 : 1.0);
        }
        return $w * $size / 1000.0 * 1.02; // 2 % Sicherheitszuschlag gegen Überlauf
    }

    private function pdfString(string $utf8): string
    {
        $win = @mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        $win = $win === false ? '?' : $win;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $win);
    }

    private function pdfDate(string $utc): string
    {
        $t = strtotime($utc . ' UTC');
        return 'D:' . gmdate('YmdHis', $t === false ? 946684800 : $t) . 'Z';
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
