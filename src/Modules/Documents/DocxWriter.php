<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use RuntimeException;
use ZipArchive;

/**
 * Erzeugt ein schlankes, gültiges DOCX (WordprocessingML) ausschliesslich mit ZipArchive.
 * Unterstützt Überschriften, Absätze und Listen. Keine Formatvorlagen-Bibliothek, keine Bilder.
 */
final class DocxWriter
{
    /** @param list<array{type:string,text:string,n:?int}> $blocks */
    public function render(string $title, array $blocks, string $createdAtUtc = '2000-01-01 00:00:00'): string
    {
        $paras = [$this->para($title, 'Title')];
        foreach ($blocks as $b) {
            $style = match ($b['type']) {
                'h1' => 'Heading1', 'h2' => 'Heading2', 'h3' => 'Heading3', default => null,
            };
            $text = $b['text'];
            if ($b['type'] === 'li') {
                $text = ($b['n'] !== null ? $b['n'] . '. ' : '• ') . $text;
            }
            $paras[] = $this->para($text, $style);
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . implode('', $paras)
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1134" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="240"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="25"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="160"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:sz w:val="23"/></w:rPr></w:style>'
            . '</w:styles>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        $iso = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($createdAtUtc . ' UTC'));
        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->x($title) . '</dc:title><dc:creator>Vertragsplattform</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $iso . '</dcterms:created></cp:coreProperties>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Datei nicht verfügbar.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('DOCX konnte nicht erzeugt werden.');
            }
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('word/document.xml', $document);
            $zip->addFromString('word/_rels/document.xml.rels', $docRels);
            $zip->addFromString('word/styles.xml', $styles);
            $zip->addFromString('docProps/core.xml', $core);
            $zip->close();
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function para(string $text, ?string $style): string
    {
        $ppr = $style === null ? '' : '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>';
        $runs = [];
        foreach (explode("\n", $text) as $i => $line) {
            $runs[] = ($i > 0 ? '<w:r><w:br/></w:r>' : '') . '<w:r><w:t xml:space="preserve">' . $this->x($line) . '</w:t></w:r>';
        }
        return '<w:p>' . $ppr . implode('', $runs) . '</w:p>';
    }

    private function x(string $s): string
    {
        // Steuerzeichen, die in XML 1.0 unzulässig sind, entfernen.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
