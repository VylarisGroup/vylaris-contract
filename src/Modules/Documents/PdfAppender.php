<?php

declare(strict_types=1);

namespace App\Modules\Documents;

/**
 * Hängt Seiten per «inkrementellem Update» an ein PDF an: Die Originalbytes bleiben unverändert (das Ergebnis beginnt mit
 * dem Original), neue Objekte, ein neuer Querverweis und ein neuer Trailer folgen.
 *
 * BEWUSSTE GRENZEN: Funktioniert nur für PDFs mit klassischem Querverweis (kein Objektstrom), genau einem Revisionsstand und den
 * Standardschriften als Objekte 3 (Helvetica) und 4 (Helvetica-Bold) – das ist exakt die Struktur, die PlainPdfRenderer erzeugt.
 * Alles andere wird abgelehnt (RuntimeException), nie geraten.
 */
final class PdfAppender
{
    /** @param list<string> $pageContents unkomprimierte Seiteninhalte (siehe PlainPdfRenderer::appendixContents) */
    public static function append(string $pdf, array $pageContents): string
    {
        if ($pageContents === []) {
            throw new \RuntimeException('Keine Seiten zum Anhängen.');
        }
        if (!str_starts_with($pdf, '%PDF-') || substr_count($pdf, 'startxref') !== 1 || preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m) !== 1) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Revision/Querverweis).');
        }
        $prevXref = (int) $m[1];
        if (substr($pdf, $prevXref, 4) !== 'xref') {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Querverweistabelle).');
        }
        $trailerPos = strpos($pdf, 'trailer', $prevXref);
        if ($trailerPos === false) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Trailer).');
        }
        $trailer = substr($pdf, $trailerPos);
        if (preg_match('#/Size\s+(\d+)#', $trailer, $t) !== 1 || preg_match('#/Root\s+(\d+)\s+0\s+R#', $trailer, $r) !== 1) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Trailer-Angaben).');
        }
        $size = (int) $t[1];
        $rootId = (int) $r[1];
        $infoRef = preg_match('#/Info\s+(\d+)\s+0\s+R#', $trailer, $i) === 1 ? ' /Info ' . (int) $i[1] . ' 0 R' : '';

        // Querverweistabelle lesen: genau ein Abschnitt "0 N", Einträge zu je 20 Bytes.
        $xrefHead = substr($pdf, $prevXref, $trailerPos - $prevXref);
        if (preg_match('/^xref\s+0\s+(\d+)\s*\R/', $xrefHead, $h) !== 1 || (int) $h[1] !== $size) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Querverweisabschnitt).');
        }
        $offsets = [];
        $entries = substr($xrefHead, strlen($h[0]));
        for ($n = 0; $n < $size; $n++) {
            $e = substr($entries, $n * 20, 20);
            if (preg_match('/^(\d{10}) (\d{5}) ([nf])/', $e, $x) !== 1) {
                throw new \RuntimeException('PDF-Struktur nicht unterstützt (Querverweiseintrag).');
            }
            $offsets[$n] = $x[3] === 'n' ? (int) $x[1] : null;
        }
        $obj = static function (int $id) use ($pdf, $offsets): string {
            $off = $offsets[$id] ?? null;
            if ($off === null || preg_match('/\G' . $id . ' 0 obj\s*(.*?)\s*endobj/s', $pdf, $mm, 0, $off) !== 1) {
                throw new \RuntimeException('PDF-Objekt ' . $id . ' nicht lesbar.');
            }
            return $mm[1];
        };
        $root = $obj($rootId);
        if (preg_match('#/Pages\s+(\d+)\s+0\s+R#', $root, $p) !== 1) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Seitenbaum).');
        }
        $pagesId = (int) $p[1];
        $pages = $obj($pagesId);
        if (preg_match('#/Kids\s*\[(.*?)\]#s', $pages, $k) !== 1 || preg_match('#/Count\s+(\d+)#', $pages, $c) !== 1 || str_contains($pages, '/Parent')) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Seitenbaum-Knoten).');
        }
        if (!str_contains($obj(3), '/BaseFont /Helvetica') || !str_contains($obj(4), '/BaseFont /Helvetica-Bold')) {
            throw new \RuntimeException('PDF-Struktur nicht unterstützt (Schriften).');
        }

        $out = $pdf;
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        $new = [];     // id => Offset
        $next = $size;
        $kids = trim($k[1]);
        foreach ($pageContents as $content) {
            $stream = gzcompress($content, 6);
            $contentId = $next++;
            $pageId = $next++;
            $new[$contentId] = strlen($out);
            $out .= $contentId . " 0 obj\n<< /Length " . strlen((string) $stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream\nendobj\n";
            $new[$pageId] = strlen($out);
            $out .= $pageId . " 0 obj\n<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>\nendobj\n";
            $kids .= ' ' . $pageId . ' 0 R';
        }
        $pagesOffset = strlen($out);
        $out .= $pagesId . " 0 obj\n<< /Type /Pages /Kids [" . $kids . '] /Count ' . ((int) $c[1] + count($pageContents)) . " >>\nendobj\n";

        $xref = strlen($out);
        $out .= "xref\n" . $pagesId . " 1\n" . sprintf("%010d 00000 n \n", $pagesOffset);
        $out .= $size . ' ' . ($next - $size) . "\n";
        for ($id = $size; $id < $next; $id++) {
            $out .= sprintf("%010d 00000 n \n", $new[$id]);
        }
        $out .= "trailer\n<< /Size {$next} /Root {$rootId} 0 R{$infoRef} /Prev {$prevXref} >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }
}
