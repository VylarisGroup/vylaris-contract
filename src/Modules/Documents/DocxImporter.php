<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\ValidationException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Extrahiert Text und einfache Struktur (Überschriften, Absätze, Listen) aus einem DOCX.
 *
 * GRENZEN: Formatierungen, Bilder, Tabellen-Layout, Kopf-/Fusszeilen, Kommentare, Änderungsverfolgung
 * und Textfelder werden NICHT übernommen. Tabellen erscheinen als Zeilen mit Trennzeichen.
 * Das Originaldokument bleibt unverändert als Datei erhalten – massgeblich ist die Originaldatei.
 *
 * Sicherheit: XML wird ohne Entity-Auflösung und ohne Netzwerkzugriff geparst; Dokumente mit
 * DOCTYPE/ENTITY-Deklarationen werden abgelehnt (XXE-/Billion-Laughs-Schutz).
 */
final class DocxImporter
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAX_XML_BYTES = 30_000_000;

    /** @return list<array{type:string,text:string,n:?int}> */
    public function extractBlocks(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ValidationException(['file' => 'Das DOCX-Dokument konnte nicht geöffnet werden.']);
        }
        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false || (int) $stat['size'] > self::MAX_XML_BYTES) {
                throw new ValidationException(['file' => 'Das DOCX-Dokument enthält keinen lesbaren Text oder ist zu gross.']);
            }
            $xml = (string) $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ValidationException(['file' => 'Das DOCX-Dokument enthält unzulässige XML-Deklarationen.']);
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new ValidationException(['file' => 'Das DOCX-Dokument ist fehlerhaft.']);
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', self::W);

        $blocks = [];
        foreach ($xp->query('//w:body//w:p') ?: [] as $p) {
            /** @var DOMElement $p */
            $text = $this->paragraphText($p, $xp);
            if (trim($text) === '') {
                continue;
            }
            $style = (string) $xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
            $isList = $xp->query('w:pPr/w:numPr', $p)?->length > 0;
            $inTable = $xp->query('ancestor::w:tc', $p)?->length > 0;
            if (preg_match('/^(Heading|berschrift|Title)\s*([1-4])?/i', $style, $m) === 1) {
                $lvl = isset($m[2]) && $m[2] !== '' ? min(3, (int) $m[2]) : 1;
                $blocks[] = ['type' => 'h' . $lvl, 'text' => trim($text), 'n' => null];
            } elseif ($isList && !$inTable) {
                $blocks[] = ['type' => 'li', 'text' => trim($text), 'n' => null];
            } else {
                $blocks[] = ['type' => 'p', 'text' => trim($text), 'n' => null];
            }
        }
        return $blocks;
    }

    private function paragraphText(DOMElement $p, DOMXPath $xp): string
    {
        $out = '';
        foreach ($xp->query('.//w:t | .//w:tab | .//w:br', $p) ?: [] as $n) {
            /** @var DOMElement $n */
            $out .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => ' ',
                default => "\n",
            };
        }
        return $out;
    }
}
