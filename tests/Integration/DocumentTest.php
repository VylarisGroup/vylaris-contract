<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\NotFoundException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\ForbiddenException;
use App\Modules\Documents\Blocks;
use App\Modules\Documents\DocxImporter;
use App\Modules\Documents\DocxWriter;
use App\Modules\Documents\PlainPdfRenderer;
use Tests\Support\TestApp;
use Tests\TestCase;
use ZipArchive;

final class DocumentTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function pdf(string $text = 'Testvertrag Inhalt'): string
    {
        return (new PlainPdfRenderer())->render('Test', [['type' => 'p', 'text' => $text, 'n' => null]]);
    }

    private function docx(string $text = 'Testvertrag Inhalt'): string
    {
        return (new DocxWriter())->render('Titel', [['type' => 'h1', 'text' => 'Abschnitt', 'n' => null], ['type' => 'p', 'text' => $text, 'n' => null], ['type' => 'li', 'text' => 'Punkt A', 'n' => null]]);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        $z = new ZipArchive();
        $z->open($tmp, ZipArchive::OVERWRITE);
        foreach ($entries as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();
        $b = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $b;
    }

    private function newDraft(): int
    {
        return $this->app->contracts->create($this->mgr, $this->app->contractInput());
    }

    private function upload(int $contractId, string $bytes, string $name, string $kind = 'attachment'): int
    {
        return $this->app->store->storeUpload($this->mgr, $contractId, $this->app->tmpFile($bytes), $name, $kind);
    }

    // ---------------------------------------------------------------- Validierung

    public function testRejectsDisguisedAndDangerousFiles(): void
    {
        $id = $this->newDraft();
        $cases = [
            'PHP als PDF getarnt (Signatur)' => ['<?php system($_GET["c"]); ?>', 'vertrag.pdf'],
            'Doppelendung' => [$this->pdf(), 'vertrag.php.pdf'],
            'ausführbare Endung' => [$this->pdf(), 'vertrag.exe'],
            'HTML' => ['<html><script>alert(1)</script></html>', 'seite.html'],
            'SVG mit Skript' => ['<svg onload="alert(1)"/>', 'bild.svg'],
            'leere Datei' => ['', 'leer.pdf'],
            'PDF mit JavaScript' => ["%PDF-1.4\n1 0 obj\n<< /S /JavaScript /JS (app.alert(1)) >>\nendobj\n%%EOF", 'js.pdf'],
            'PDF mit Launch' => ["%PDF-1.4\n1 0 obj\n<< /S /Launch /F (cmd.exe) >>\nendobj\n%%EOF", 'launch.pdf'],
            'DOCX mit Makros' => [$this->zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>', 'word/vbaProject.bin' => 'MZ']), 'makro.docx'],
            'DOCX-Pfadtraversierung' => [$this->zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>', '../evil.txt' => 'x']), 'trav.docx'],
            'ZIP als DOCX ohne Struktur' => [$this->zip(['a.txt' => 'x']), 'kein.docx'],
            'PNG-Endung, JPEG-Inhalt' => ["\xFF\xD8\xFF\xE0" . str_repeat('x', 30), 'bild.png'],
        ];
        foreach ($cases as $label => [$bytes, $name]) {
            $this->assertThrows(ValidationException::class, fn () => $this->upload($id, $bytes, $name), $label);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM documents WHERE contract_id = ?', [$id]), 'nichts gespeichert');
    }

    public function testRejectsOversizedFile(): void
    {
        $id = $this->newDraft();
        $big = "%PDF-1.4\n" . str_repeat('A', 2_000_100);
        $this->assertThrows(ValidationException::class, fn () => $this->upload($id, $big, 'gross.pdf'));
    }

    public function testAcceptsValidPdfDocxAndRejectsNonDocumentKindsForUsers(): void
    {
        $id = $this->newDraft();
        $this->assertTrue($this->upload($id, $this->pdf(), 'a.pdf') > 0);
        $this->assertTrue($this->upload($id, $this->docx(), 'b.docx') > 0);
        $this->assertThrows(ValidationException::class, fn () => $this->upload($id, $this->pdf(), 'c.pdf', 'signed'), 'Benutzer dürfen keine "signed"-Dokumente hochladen');
        $this->assertThrows(ValidationException::class, fn () => $this->upload($id, $this->pdf(), 'c.pdf', 'evidence'));
    }

    // ---------------------------------------------------------------- Speicherung

    public function testFileIsStoredUnderRandomKeyWithHashAndSafeDisplayName(): void
    {
        $id = $this->newDraft();
        $bytes = $this->pdf('Geheimer Inhalt');
        $docId = $this->upload($id, $bytes, '../../etc/Mein Vertrag (final).pdf');
        $d = $this->db->one('SELECT * FROM documents WHERE id = ?', [$docId]);
        $this->assertSame('Mein Vertrag (final).pdf', $d['original_filename'], 'Pfadanteile entfernt, nur Metadatum');
        $this->assertTrue(preg_match('/^[a-f0-9]{40}$/', (string) $d['storage_key']) === 1);
        $path = $this->app->store->absolutePath($d);
        $this->assertTrue(str_starts_with($path, realpath($this->app->storageDir) . '/'), 'liegt im privaten Speicher');
        $this->assertFalse(str_contains($path, 'Vertrag'), 'Originalname nicht im Dateipfad');
        $this->assertSame(hash('sha256', $bytes), $d['sha256']);
        $this->assertSame(hash_file('sha256', $path), $d['sha256'], 'Hash der tatsächlich gespeicherten Datei');
        $this->assertSame(strlen($bytes), (int) $d['size_bytes']);
        $this->assertSame('application/pdf', $d['mime_type']);
    }

    public function testTwoUploadsOfSameNameNeverCollide(): void
    {
        $id = $this->newDraft();
        $a = $this->db->value('SELECT storage_key FROM documents WHERE id = ?', [$this->upload($id, $this->pdf('A'), 'x.pdf')]);
        $b = $this->db->value('SELECT storage_key FROM documents WHERE id = ?', [$this->upload($id, $this->pdf('B'), 'x.pdf')]);
        $this->assertTrue($a !== $b);
    }

    public function testFailedDatabaseInsertLeavesNoOrphanFile(): void
    {
        $id = $this->newDraft();
        $before = $this->countFiles();
        try {
            // Ungültige Version -> Validierungsfehler VOR dem Speichern: nichts darf bleiben
            $this->app->store->storeUpload($this->mgr, $id, $this->app->tmpFile($this->pdf()), 'x.pdf', 'attachment', 999999);
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->countFiles());
    }

    private function countFiles(): int
    {
        $n = 0;
        if (!is_dir($this->app->storageDir)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->app->storageDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && !str_starts_with($f->getFilename(), 'upload_')) {
                $n++;
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------- Auslieferung

    public function testDownloadRequiresAuthorizationAndIsLogged(): void
    {
        $id = $this->newDraft();
        $docId = $this->upload($id, $this->pdf(), 'x.pdf');
        $r = $this->app->store->download($this->mgr, $docId);
        $this->assertTrue($r instanceof Response);
        $this->assertSame(200, $r->status);
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertContains('attachment', $r->headers['Content-Disposition']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'document.downloaded' AND object_id = ?", [$docId]));

        $otherTenant = $this->app->fx->tenant();
        $foreign = $this->app->actor($otherTenant, 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->store->download($foreign, $docId), 'fremder Mandant');

        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->store->download($reader, $docId), 'Leser ohne Zuweisung');
    }

    public function testDownloadFilenameHeaderCannotBeInjected(): void
    {
        $id = $this->newDraft();
        $docId = $this->upload($id, $this->pdf(), "evil\r\nSet-Cookie: x=1\".pdf");
        $r = $this->app->store->download($this->mgr, $docId);
        $this->assertFalse(str_contains($r->headers['Content-Disposition'], "\n"));
        $this->assertFalse(str_contains($r->headers['Content-Disposition'], "\r"));
        $this->assertFalse(str_contains($r->headers['Content-Disposition'], 'Set-Cookie: '));
    }

    public function testTamperedStoredFileIsDetectedOnDownload(): void
    {
        $id = $this->newDraft();
        $docId = $this->upload($id, $this->pdf('Original'), 'x.pdf');
        $d = $this->db->one('SELECT * FROM documents WHERE id = ?', [$docId]);
        file_put_contents($this->app->store->absolutePath($d), $this->pdf('MANIPULIERT'));
        $e = $this->assertThrows(BusinessRuleException::class, fn () => $this->app->store->download($this->mgr, $docId));
        $this->assertContains('Integritätsprüfung', $e->getMessage());
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'integrity.violation' AND object_id = ?", [$docId]));
    }

    public function testStorageKeyCannotEscapeStorageRoot(): void
    {
        $this->assertThrows(\RuntimeException::class, fn () => $this->app->store->absolutePath(['storage_key' => '../../etc/passwd']));
        $this->assertThrows(\RuntimeException::class, fn () => $this->app->store->absolutePath(['storage_key' => str_repeat('a', 40)]), 'nicht vorhanden');
    }

    public function testExternalPartySeesOnlyDocumentsMarkedVisibleForApprovedVersion(): void
    {
        $id = $this->newDraft();
        $this->app->contracts->saveBody($this->mgr, $id, '<p>Text</p>');
        $vid = $this->app->fx->approve($id);
        $visible = $this->app->store->storeBytes($this->tenant, $id, $vid, $this->pdf(), 'sichtbar.pdf', 'application/pdf', 'generated', $this->mgr->userId, true);
        $hidden = $this->app->store->storeBytes($this->tenant, $id, $vid, $this->pdf(), 'intern.pdf', 'application/pdf', 'attachment', $this->mgr->userId, false);
        $ext = $this->app->fx->user('external_party');
        $this->app->fx->member($this->tenant, $ext, 'external_party');
        $this->app->fx->mandate($this->tenant, $ext, 'counterparty', [$id], ['read', 'download'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $this->mgr->userId);
        $extP = $this->app->ac->principal($ext, null);

        $list = $this->app->store->listFor($extP, $id);
        $this->assertSame([$visible], array_map(fn ($r) => (int) $r['id'], $list));
        $this->assertSame(200, $this->app->store->download($extP, $visible)->status);
        $this->assertThrows(NotFoundException::class, fn () => $this->app->store->download($extP, $hidden));
    }

    // ---------------------------------------------------------------- Import

    public function testImportDocxExtractsTextIntoDraftVersionAndKeepsOriginal(): void
    {
        $id = $this->newDraft();
        $r = $this->app->documents->import($this->mgr, $id, $this->app->tmpFile($this->docx('Der Anbieter liefert.')), 'Vorlage.docx');
        $this->assertSame('docx_text', $r['mode']);
        $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ?', [$r['version_id']]);
        $this->assertContains('Der Anbieter liefert.', (string) $v['body_text']);
        $this->assertContains('<h1>Abschnitt</h1>', (string) $v['body_html']);
        $doc = $this->db->one('SELECT * FROM documents WHERE id = ?', [$r['document_id']]);
        $this->assertSame('original', $doc['kind']);
        $this->assertSame($r['version_id'], (int) $doc['contract_version_id']);
    }

    public function testImportPdfKeepsOriginalOnlyAndBindsHashIntoVersionText(): void
    {
        $id = $this->newDraft();
        $bytes = $this->pdf('Importierter Vertrag');
        $r = $this->app->documents->import($this->mgr, $id, $this->app->tmpFile($bytes), 'Scan.pdf');
        $this->assertSame('pdf_original_only', $r['mode']);
        $v = $this->db->one('SELECT body_text, body_html FROM contract_versions WHERE id = ?', [$r['version_id']]);
        $this->assertContains(hash('sha256', $bytes), (string) $v['body_text'], 'SHA-256 der Originaldatei ist Teil des freizugebenden Textes');
        $this->assertFalse(str_contains((string) $v['body_text'], 'Importierter Vertrag'), 'Es wird keine Texterkennung vorgetäuscht');
    }

    public function testImportOnlyIntoDraft(): void
    {
        $id = $this->newDraft();
        $this->app->contracts->saveBody($this->mgr, $id, '<p>x</p>');
        $this->app->fx->approve($id);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->documents->import($this->mgr, $id, $this->app->tmpFile($this->docx()), 'a.docx'));
    }

    public function testDocxWithEntityDeclarationIsRejected(): void
    {
        $evil = $this->zip([
            '[Content_Types].xml' => '<Types/>',
            'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>',
        ]);
        $id = $this->newDraft();
        $this->assertThrows(ValidationException::class, fn () => $this->app->documents->import($this->mgr, $id, $this->app->tmpFile($evil), 'xxe.docx'));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM documents WHERE contract_id = ?', [$id]));
    }

    public function testDocxRoundTripPreservesStructureAndText(): void
    {
        $blocks = [['type' => 'h1', 'text' => 'Überschrift ÄÖÜ', 'n' => null], ['type' => 'p', 'text' => 'Absatz mit Umlauten: äöü & <Zeichen>', 'n' => null]];
        $bytes = (new DocxWriter())->render('T', $blocks);
        $path = $this->app->tmpFile($bytes);
        $out = (new DocxImporter())->extractBlocks($path);
        // Der Titel wird als erste Überschrift gelesen
        $texts = array_column($out, 'text');
        $this->assertTrue(in_array('Überschrift ÄÖÜ', $texts, true));
        $this->assertTrue(in_array('Absatz mit Umlauten: äöü & <Zeichen>', $texts, true));
    }

    // ---------------------------------------------------------------- Export / signierbare Fassung

    public function testExportRequiresPermissionAndLogs(): void
    {
        $id = $this->newDraft();
        $this->app->contracts->saveBody($this->mgr, $id, '<p>Text</p>');
        $pdf = $this->app->documents->exportPdf($this->mgr, $id);
        $this->assertTrue(str_starts_with($pdf['bytes'], '%PDF-') && str_contains($pdf['bytes'], '%%EOF'));
        $this->assertSame('application/pdf', $pdf['mime']);
        $docx = $this->app->documents->exportDocx($this->mgr, $id);
        $this->assertTrue(str_starts_with($docx['bytes'], "PK\x03\x04"));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'contract.exported' AND actor_user_id = ?", [$this->mgr->userId]));

        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->documents->exportPdf($reader, $id));
    }

    public function testPdfRenderingIsDeterministic(): void
    {
        $id = $this->newDraft();
        $this->app->contracts->saveBody($this->mgr, $id, '<h1>A</h1><p>Text</p>');
        $a = $this->app->documents->exportPdf($this->mgr, $id)['bytes'];
        $b = $this->app->documents->exportPdf($this->mgr, $id)['bytes'];
        $this->assertSame($a, $b);
    }

    public function testSignableDocumentIsGeneratedOnceAndNeverReplaced(): void
    {
        $id = $this->newDraft();
        $this->app->contracts->saveBody($this->mgr, $id, '<p>Vertragstext</p>');
        $draftVersion = (int) $this->db->value('SELECT current_version_id FROM contracts WHERE id = ?', [$id]);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->documents->signableDocument($id, $draftVersion, $this->mgr->userId), 'nur gesperrte Versionen');

        $vid = $this->app->fx->approve($id);
        $d1 = $this->app->documents->signableDocument($id, $vid, $this->mgr->userId);
        $d2 = $this->app->documents->signableDocument($id, $vid, $this->mgr->userId);
        $this->assertSame((int) $d1['id'], (int) $d2['id']);
        $this->assertSame($d1['sha256'], $d2['sha256']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM documents WHERE contract_version_id = ? AND kind = 'generated'", [$vid]));
        $this->assertSame(hash_file('sha256', $this->app->store->absolutePath($d1)), $d1['sha256']);
    }

    public function testSignableDocumentUsesImportedOriginalPdfUnchanged(): void
    {
        $id = $this->newDraft();
        $bytes = $this->pdf('Mein Original');
        $r = $this->app->documents->import($this->mgr, $id, $this->app->tmpFile($bytes), 'o.pdf');
        $this->app->fx->approve($id);
        $d = $this->app->documents->signableDocument($id, $r['version_id'], $this->mgr->userId);
        $this->assertSame((int) $r['document_id'], (int) $d['id']);
        $this->assertSame(hash('sha256', $bytes), $d['sha256']);
    }
}
