<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\BusinessRuleException;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\ContractService;
use App\Services\AccessControl;
use RuntimeException;

/**
 * Import und Export von Vertragsdokumenten sowie die signierbare PDF-Fassung einer Version.
 *
 * IMPORT-GRENZEN (ausdrücklich):
 *  - DOCX: Text und einfache Struktur werden übernommen; Formatierung, Bilder, Kopf-/Fusszeilen
 *    und Änderungsverfolgung gehen nicht in die bearbeitbare Fassung über. Die Originaldatei bleibt
 *    unverändert erhalten und ist die massgebliche Fassung.
 *  - PDF: Wird als unveränderliches Originaldokument geführt. Es erfolgt KEINE Textextraktion und
 *    KEINE OCR; der Inhalt ist daher nicht volltextdurchsuchbar. Die Freigabe bezieht sich über den
 *    SHA-256 auf die exakte PDF-Datei.
 */
final class ContractDocumentService
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly ContractService $contracts,
        private readonly DocumentStore $store,
        private readonly UploadValidator $validator,
        private readonly DocxImporter $docxImporter,
        private readonly PlainPdfRenderer $pdf,
        private readonly DocxWriter $docx,
    ) {
    }

    /**
     * @return array{document_id:int,version_id:int,mode:string}
     */
    public function import(Principal $p, int $contractId, string $tmpPath, string $originalName): array
    {
        $c = $this->ac->contract($p, $contractId, 'edit');
        $this->ac->require($p, 'contract.create');
        if ($c['status'] !== 'draft') {
            throw new BusinessRuleException('Dokumente können nur in einen Entwurf importiert werden. Erstellen Sie ggf. zuerst eine neue Version.');
        }
        $info = $this->validator->validate($tmpPath, $originalName, ['pdf', 'docx']);

        return $this->db->transaction(function () use ($p, $contractId, $tmpPath, $info): array {
            if ($info['extension'] === 'docx') {
                $blocks = $this->docxImporter->extractBlocks($tmpPath);
                if ($blocks === []) {
                    throw new ValidationException(['file' => 'Im DOCX-Dokument wurde kein Text gefunden.']);
                }
                $versionId = $this->contracts->saveBody($p, $contractId, Blocks::toHtml($blocks), 'Import aus ' . $info['safe_name']);
                $docId = $this->store->storeUpload($p, $contractId, $tmpPath, $info['safe_name'], 'original', $versionId, false);
                $mode = 'docx_text';
            } else {
                $sha = hash_file('sha256', $tmpPath) ?: '';
                $notice = '<p>Importiertes Dokument (PDF). Der Vertragsinhalt wird als unveränderliche Originaldatei geführt.</p>'
                    . '<p>Dateiname: ' . htmlspecialchars($info['safe_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                    . '<p>SHA-256 der Originaldatei: ' . $sha . '</p>';
                $versionId = $this->contracts->saveBody($p, $contractId, $notice, 'Import aus ' . $info['safe_name']);
                $docId = $this->store->storeUpload($p, $contractId, $tmpPath, $info['safe_name'], 'original', $versionId, false);
                $stored = $this->db->one('SELECT sha256 FROM documents WHERE id = ?', [$docId]);
                if ($stored === null || !hash_equals($sha, (string) $stored['sha256'])) {
                    throw new RuntimeException('Gespeicherte Datei weicht vom hochgeladenen PDF ab.');
                }
                $mode = 'pdf_original_only';
            }
            $this->audit->log('document.imported', 'success', (int) $this->db->value('SELECT tenant_id FROM contracts WHERE id = ?', [$contractId]), $p->userId, 'contract', $contractId, ['mode' => $mode, 'version_id' => $versionId]);
            return ['document_id' => $docId, 'version_id' => $versionId, 'mode' => $mode];
        });
    }

    /** HTML-Ausgabe (bereits bereinigt) für Vorschau. */
    public function previewHtml(Principal $p, int $contractId, ?int $versionId = null): string
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        $v = $this->resolveVersion($p, $c, $versionId);
        return HtmlSanitizer::clean((string) $v['body_html']);
    }

    /** @return array{bytes:string,filename:string,mime:string} */
    public function exportPdf(Principal $p, int $contractId, ?int $versionId = null): array
    {
        $c = $this->ac->contract($p, $contractId, 'export');
        $v = $this->resolveVersion($p, $c, $versionId);
        $original = $this->originalPdf((int) $v['id']);
        if ($original !== null) {
            $bytes = $this->store->readBytes($original);
        } else {
            $bytes = $this->renderPdf($c, $v);
        }
        $this->audit->log('contract.exported', 'success', (int) $c['tenant_id'], $p->userId, 'contract_version', (int) $v['id'], ['format' => 'pdf']);
        return ['bytes' => $bytes, 'filename' => $this->exportName($c, $v, 'pdf'), 'mime' => 'application/pdf'];
    }

    /** @return array{bytes:string,filename:string,mime:string} */
    public function exportDocx(Principal $p, int $contractId, ?int $versionId = null): array
    {
        $c = $this->ac->contract($p, $contractId, 'export');
        $v = $this->resolveVersion($p, $c, $versionId);
        if ($this->originalPdf((int) $v['id']) !== null) {
            throw new BusinessRuleException('Diese Version besteht aus einem importierten PDF und kann nicht als DOCX exportiert werden.');
        }
        $blocks = Blocks::fromHtml(HtmlSanitizer::clean((string) $v['body_html']));
        $bytes = $this->docx->render((string) $c['title'], $blocks, (string) ($v['locked_at'] ?? $v['created_at']));
        $this->audit->log('contract.exported', 'success', (int) $c['tenant_id'], $p->userId, 'contract_version', (int) $v['id'], ['format' => 'docx']);
        return ['bytes' => $bytes, 'filename' => $this->exportName($c, $v, 'docx'), 'mime' => UploadValidator::MIME_DOCX];
    }

    /**
     * Liefert das Dokument, das signiert bzw. dem Notariat bereitgestellt wird: ein importiertes
     * Original-PDF oder die EINMAL erzeugte, gespeicherte PDF-Fassung der gesperrten Version.
     * Eine bereits erzeugte Fassung wird NIE ersetzt (gleiche Bytes, gleicher Hash).
     *
     * @return array<string,mixed> documents-Zeile
     */
    public function signableDocument(int $contractId, int $versionId, ?int $actorId): array
    {
        $c = $this->db->one('SELECT * FROM contracts WHERE id = ?', [$contractId]);
        $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ? AND contract_id = ?', [$versionId, $contractId]);
        if ($c === null || $v === null) {
            throw new NotFoundException();
        }
        if ($v['state'] !== 'locked') {
            throw new BusinessRuleException('Nur gesperrte (freigabefähige) Versionen können als PDF bereitgestellt werden.');
        }
        $original = $this->originalPdf($versionId);
        if ($original !== null) {
            return $original;
        }
        $lock = 'pdf_gen_' . $versionId;
        if ((int) $this->db->value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            throw new RuntimeException('PDF-Erzeugung ist gerade belegt.');
        }
        try {
            $existing = $this->db->one("SELECT * FROM documents WHERE contract_version_id = ? AND kind = 'generated' AND mime_type = 'application/pdf' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", [$versionId]);
            if ($existing !== null) {
                return $existing;
            }
            $bytes = $this->renderPdf($c, $v);
            $id = $this->store->storeBytes((int) $c['tenant_id'], $contractId, $versionId, $bytes, $this->exportName($c, $v, 'pdf'), 'application/pdf', 'generated', $actorId, true);
            return (array) $this->db->one('SELECT * FROM documents WHERE id = ?', [$id]);
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function resolveVersion(Principal $p, array $c, ?int $versionId): array
    {
        $id = $versionId ?? (int) $c['current_version_id'];
        $v = $this->db->one('SELECT * FROM contract_versions WHERE id = ? AND contract_id = ? AND tenant_id = ?', [$id, (int) $c['id'], (int) $c['tenant_id']]);
        if ($v === null) {
            throw new NotFoundException();
        }
        return $v;
    }

    /** @return array<string,mixed>|null */
    private function originalPdf(int $versionId): ?array
    {
        return $this->db->one(
            "SELECT * FROM documents WHERE contract_version_id = ? AND kind = 'original' AND mime_type = 'application/pdf' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
            [$versionId],
        );
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $v */
    private function renderPdf(array $c, array $v): string
    {
        $blocks = Blocks::fromHtml(HtmlSanitizer::clean((string) $v['body_html']));
        return $this->pdf->render((string) $c['title'], $blocks, [
            'contract_number' => (string) $c['contract_number'],
            'version_no' => (int) $v['version_no'],
            'content_hash' => (string) ($v['content_hash'] ?? hash('sha256', (string) $v['body_html'])),
            'created_at' => (string) ($v['locked_at'] ?? $v['created_at']),
        ]);
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $v */
    private function exportName(array $c, array $v, string $ext): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $c['contract_number'] . '_v' . $v['version_no']) . '.' . $ext;
    }
}
