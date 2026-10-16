<?php

declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\BusinessRuleException;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\Response;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;
use RuntimeException;

/**
 * Privater Dokumentenspeicher ausserhalb des Webroots.
 *
 *  - Dateien liegen unter zufälligen Schlüsseln (160 Bit) in {root}/{ab}/{cd}/{key}; der
 *    Originalname ist reines Metadatum.
 *  - Der SHA-256 wird über die TATSÄCHLICH gespeicherte Datei gebildet und beim Download erneut
 *    geprüft (Manipulationserkennung).
 *  - Auslieferung nur nach serverseitiger Berechtigungsprüfung (AccessControl::document).
 */
final class DocumentStore
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessControl $ac,
        private readonly AuditLogger $audit,
        private readonly UploadValidator $validator,
        private readonly string $root,
    ) {
        if (!is_dir($this->root) && !@mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new RuntimeException('Dokumentenspeicher kann nicht angelegt werden.');
        }
    }

    /**
     * Benutzer-Upload. Nur die Arten "original" und "attachment" sind für Benutzer erlaubt.
     */
    public function storeUpload(Principal $p, int $contractId, string $tmpPath, string $originalName, string $kind = 'attachment', ?int $versionId = null, bool $visibleExternal = false): int
    {
        if (!in_array($kind, ['original', 'attachment'], true)) {
            throw new ValidationException(['kind' => 'Ungültige Dokumentart.']);
        }
        $c = $this->ac->contract($p, $contractId, 'edit');
        $tenantId = (int) $c['tenant_id'];
        if (in_array($c['status'], ['archived', 'review', 'signature'], true)) {
            throw new BusinessRuleException('Im aktuellen Status können keine Dokumente hinzugefügt werden.');
        }
        if ($kind === 'original' && $c['status'] !== 'draft') {
            throw new BusinessRuleException('Ein Originaldokument kann nur im Entwurf hinterlegt werden.');
        }
        if ($versionId !== null) {
            $v = $this->db->one('SELECT id, state FROM contract_versions WHERE id = ? AND contract_id = ? AND tenant_id = ?', [$versionId, $contractId, $tenantId]);
            if ($v === null) {
                throw new ValidationException(['version' => 'Ungültige Version.']);
            }
        }
        $allowed = $kind === 'original' ? ['pdf', 'docx'] : ['pdf', 'docx', 'png', 'jpg', 'jpeg'];
        $info = $this->validator->validate($tmpPath, $originalName, $allowed);
        $id = $this->persist($tenantId, $contractId, $versionId, $tmpPath, $info['safe_name'], $info['mime'], $kind, $p->userId, $visibleExternal, true);
        $this->audit->log('document.uploaded', 'success', $tenantId, $p->userId, 'document', $id, ['kind' => $kind, 'size' => $info['size'], 'mime' => $info['mime']]);
        return $id;
    }

    /** Interne Ablage erzeugter Inhalte (PDF-Fassung, Signaturbelege, Notariats-Rückläufer). */
    public function storeBytes(int $tenantId, int $contractId, ?int $versionId, string $bytes, string $filename, string $mime, string $kind, ?int $uploadedBy, bool $visibleExternal = false): int
    {
        if (!in_array($kind, ['generated', 'signed', 'evidence', 'notary_return', 'original', 'attachment'], true)) {
            throw new ValidationException(['kind' => 'Ungültige Dokumentart.']);
        }
        $tmp = tempnam($this->root, '.tmp');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        try {
            file_put_contents($tmp, $bytes, LOCK_EX);
            $id = $this->persist($tenantId, $contractId, $versionId, $tmp, UploadValidator::safeDisplayName($filename), $mime, $kind, $uploadedBy, $visibleExternal, false);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $this->audit->log('document.stored', 'success', $tenantId, $uploadedBy, 'document', $id, ['kind' => $kind, 'mime' => $mime]);
        return $id;
    }

    private function persist(int $tenantId, int $contractId, ?int $versionId, string $srcPath, string $displayName, string $mime, string $kind, ?int $uploadedBy, bool $visibleExternal, bool $fromUpload): int
    {
        $key = bin2hex(random_bytes(20));
        $dir = $this->root . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Speicherverzeichnis kann nicht angelegt werden.');
        }
        $dest = $dir . '/' . $key;
        $ok = ($fromUpload && is_uploaded_file($srcPath)) ? move_uploaded_file($srcPath, $dest) : copy($srcPath, $dest);
        if (!$ok) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }
        @chmod($dest, 0640);
        $sha = Crypto::sha256File($dest);      // Hash der tatsächlich gespeicherten Datei
        $size = (int) filesize($dest);
        try {
            $this->db->run(
                'INSERT INTO documents (tenant_id, contract_id, contract_version_id, kind, storage_key, original_filename, mime_type, size_bytes, sha256, visible_to_external, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$tenantId, $contractId, $versionId, $kind, $key, $displayName, $mime, $size, $sha, $visibleExternal ? 1 : 0, $uploadedBy],
            );
        } catch (\Throwable $e) {
            @unlink($dest); // keine verwaiste Datei ohne Datenbankeintrag zurücklassen
            throw $e;
        }
        return $this->db->lastId();
    }

    /** Absoluter Pfad; stellt sicher, dass er im Speicherwurzelverzeichnis liegt. */
    public function absolutePath(array $doc): string
    {
        $key = (string) $doc['storage_key'];
        if (preg_match('/^[a-f0-9]{40}$/', $key) !== 1) {
            throw new RuntimeException('Ungültiger Speicherschlüssel.');
        }
        $path = $this->root . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key;
        $real = realpath($path);
        $rootReal = realpath($this->root);
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Dokumentdatei fehlt im Speicher.');
        }
        return $real;
    }

    /** Prüft die gespeicherte Datei gegen den Hash aus der Datenbank. */
    public function verifyIntegrity(array $doc): void
    {
        $path = $this->absolutePath($doc);
        if (!hash_equals((string) $doc['sha256'], Crypto::sha256File($path))) {
            $this->audit->log('integrity.violation', 'failure', (int) $doc['tenant_id'], null, 'document', (int) $doc['id'], ['where' => 'file_hash_mismatch']);
            throw new BusinessRuleException('Integritätsprüfung fehlgeschlagen: Die gespeicherte Datei entspricht nicht ihrem Hash. Bitte Administrator informieren.');
        }
    }

    public function readBytes(array $doc): string
    {
        $this->verifyIntegrity($doc);
        return (string) file_get_contents($this->absolutePath($doc));
    }

    /** Berechtigungsgeprüfte Auslieferung inklusive Protokollierung und Integritätsprüfung. */
    public function download(Principal $p, int $documentId): Response
    {
        $doc = $this->ac->document($p, $documentId, 'download');
        $this->verifyIntegrity($doc);
        $this->audit->log('document.downloaded', 'success', (int) $doc['tenant_id'], $p->userId, 'document', $documentId, ['kind' => $doc['kind'], 'external' => $p->isExternal()]);
        return Response::download($this->absolutePath($doc), (string) $doc['original_filename'], (string) $doc['mime_type']);
    }

    /** @return list<array<string,mixed>> Dokumente eines Vertrags, die der Handelnde sehen darf. */
    public function listFor(Principal $p, int $contractId): array
    {
        $c = $this->ac->contract($p, $contractId, 'view');
        $sql = 'SELECT id, kind, original_filename, mime_type, size_bytes, sha256, contract_version_id, created_at, visible_to_external
                  FROM documents WHERE contract_id = ? AND tenant_id = ? AND deleted_at IS NULL';
        $params = [$contractId, (int) $c['tenant_id']];
        if ($p->isExternal()) {
            $sql .= ' AND visible_to_external = 1';
            if ($c['approved_version_id'] !== null) {
                $sql .= ' AND (contract_version_id IS NULL OR contract_version_id = ?)';
                $params[] = (int) $c['approved_version_id'];
            }
            if ($p->userType === 'notary') {
                $sql .= " AND id IN (SELECT document_id FROM notary_cases WHERE notary_user_id = ? AND status <> 'prepared' AND document_id IS NOT NULL)";
                $params[] = $p->userId;
            }
        } elseif (!$p->can('document.download')) {
            throw new ForbiddenException();
        }
        return $this->db->all($sql . ' ORDER BY created_at DESC, id DESC', $params);
    }
}
