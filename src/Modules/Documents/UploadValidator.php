<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\ValidationException;
use finfo;
use ZipArchive;

/**
 * Prüft hochgeladene Dateien: Endung, MIME-Typ (serverseitig ermittelt), Dateisignatur,
 * Grösse und – je Format – aktive/gefährliche Inhalte.
 *
 * GRENZEN: Dies ist keine Virenprüfung. PDF-Prüfung sucht nach aktiven Inhalten im Rohbyte-Strom;
 * in komprimierten Objektströmen versteckte Inhalte werden nicht erkannt. Für Produktivbetrieb
 * mit externen Uploads wird ein Virenscanner (z. B. ClamAV) empfohlen (docs/SECURITY.md).
 */
final class UploadValidator
{
    public const MIME_PDF = 'application/pdf';
    public const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** @var array<string,string> Endung => kanonischer MIME-Typ */
    private const ALLOWED = [
        'pdf' => self::MIME_PDF,
        'docx' => self::MIME_DOCX,
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    private const ZIP_MAX_ENTRIES = 3000;
    private const ZIP_MAX_UNCOMPRESSED = 150_000_000;

    public function __construct(private readonly int $maxBytes = 20_000_000)
    {
    }

    /**
     * @param list<string> $allowedExtensions
     * @return array{extension:string,mime:string,size:int,safe_name:string}
     */
    public function validate(string $path, string $originalName, array $allowedExtensions = ['pdf', 'docx', 'png', 'jpg', 'jpeg']): array
    {
        $err = static function (string $m): never {
            throw new ValidationException(['file' => $m]);
        };
        if (!is_file($path) || !is_readable($path)) {
            $err('Die Datei konnte nicht gelesen werden.');
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            $err('Die Datei ist leer.');
        }
        if ($size > $this->maxBytes) {
            $err('Die Datei ist zu gross (maximal ' . (int) ($this->maxBytes / 1_000_000) . ' MB).');
        }
        $safeName = self::safeDisplayName($originalName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext]) || !in_array($ext, $allowedExtensions, true)) {
            $err('Dieser Dateityp ist nicht erlaubt. Erlaubt: ' . implode(', ', $allowedExtensions) . '.');
        }
        // Doppelte Endungen wie "vertrag.php.pdf" oder ausführbare Zwischenendungen ablehnen.
        if (preg_match('/\.(php\d?|phtml|phar|pht|exe|sh|bat|cmd|js|html?|svg|jsp|asp|aspx|cgi|pl|py)(\.|$)/i', $safeName) === 1) {
            $err('Der Dateiname enthält eine nicht erlaubte Endung.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

        switch ($ext) {
            case 'pdf':
                if (!str_starts_with($head, '%PDF-') || $detected !== self::MIME_PDF) {
                    $err('Die Datei ist kein gültiges PDF.');
                }
                $this->assertNoActivePdfContent($path);
                break;
            case 'docx':
                if (!str_starts_with($head, "PK\x03\x04") || !in_array($detected, [self::MIME_DOCX, 'application/zip', 'application/octet-stream'], true)) {
                    $err('Die Datei ist kein gültiges DOCX-Dokument.');
                }
                $this->assertSafeDocx($path);
                break;
            case 'png':
                if (!str_starts_with($head, "\x89PNG\r\n\x1a\n") || $detected !== 'image/png') {
                    $err('Die Datei ist kein gültiges PNG-Bild.');
                }
                $this->assertImage($path);
                break;
            default: // jpg, jpeg
                if (!str_starts_with($head, "\xFF\xD8\xFF") || $detected !== 'image/jpeg') {
                    $err('Die Datei ist kein gültiges JPEG-Bild.');
                }
                $this->assertImage($path);
        }
        return ['extension' => $ext, 'mime' => self::ALLOWED[$ext], 'size' => $size, 'safe_name' => $safeName];
    }

    /** Anzeigename: nur Basisname, ohne Steuerzeichen/Pfade; wird NIE für Dateisystempfade verwendet. */
    public static function safeDisplayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'dokument';
        }
        return mb_substr($name, 0, 200);
    }

    private function assertNoActivePdfContent(string $path): void
    {
        $raw = (string) file_get_contents($path);
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA)\b#', $raw) === 1) {
            throw new ValidationException(['file' => 'Das PDF enthält aktive Inhalte (z. B. JavaScript oder eingebettete Dateien) und wird abgelehnt.']);
        }
    }

    private function assertSafeDocx(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ValidationException(['file' => 'Das DOCX-Dokument ist beschädigt.']);
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::ZIP_MAX_ENTRIES) {
                throw new ValidationException(['file' => 'Das DOCX-Dokument hat eine ungültige Struktur.']);
            }
            $total = 0;
            $hasDoc = false;
            $hasTypes = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    throw new ValidationException(['file' => 'Das DOCX-Dokument ist beschädigt.']);
                }
                $name = (string) $st['name'];
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new ValidationException(['file' => 'Das DOCX-Dokument enthält unzulässige Pfade.']);
                }
                $total += (int) $st['size'];
                if ($total > self::ZIP_MAX_UNCOMPRESSED) {
                    throw new ValidationException(['file' => 'Das DOCX-Dokument ist entpackt zu gross.']);
                }
                $lower = strtolower($name);
                if (str_contains($lower, 'vbaproject') || str_ends_with($lower, '.exe') || str_ends_with($lower, '.dll') || str_contains($lower, 'activex')) {
                    throw new ValidationException(['file' => 'Das DOCX-Dokument enthält Makros oder ausführbare Inhalte und wird abgelehnt.']);
                }
                $hasDoc = $hasDoc || $name === 'word/document.xml';
                $hasTypes = $hasTypes || $name === '[Content_Types].xml';
                if (str_ends_with($lower, '.rels')) {
                    $rels = (string) $zip->getFromIndex($i);
                    // Entfernte Vorlagen (Template-Injection) und externe Objekte ablehnen.
                    if (stripos($rels, 'attachedTemplate') !== false || preg_match('/TargetMode="External"[^>]*Type="[^"]*(oleObject|attachedTemplate|frame)/i', $rels) === 1) {
                        throw new ValidationException(['file' => 'Das DOCX-Dokument verweist auf externe Inhalte und wird abgelehnt.']);
                    }
                }
            }
            if (!$hasDoc || !$hasTypes) {
                throw new ValidationException(['file' => 'Das DOCX-Dokument ist unvollständig.']);
            }
        } finally {
            $zip->close();
        }
    }

    private function assertImage(string $path): void
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 80_000_000) {
            throw new ValidationException(['file' => 'Das Bild ist ungültig oder zu gross.']);
        }
    }
}
