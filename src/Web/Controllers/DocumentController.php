<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Web\Controller;

final class DocumentController extends Controller
{
    /** @return array{0:string,1:string} tmp-Pfad und Originalname des Uploads */
    private function incoming(Request $r): array
    {
        $f = $r->files['file'] ?? null;
        if (!is_array($f) || !isset($f['tmp_name']) || is_array($f['tmp_name'])) {
            throw new ValidationException(['file' => 'Bitte eine Datei auswählen.']);
        }
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu gross.',
                UPLOAD_ERR_NO_FILE => 'Bitte eine Datei auswählen.',
                default => 'Der Upload ist fehlgeschlagen.',
            }]);
        }
        $tmp = (string) $f['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new ValidationException(['file' => 'Ungültiger Upload.']);
        }
        return [$tmp, (string) ($f['name'] ?? 'datei')];
    }

    /** @param array<string,mixed> $params */
    public function upload(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Dokument gespeichert.', function () use ($r, $id): void {
            [$tmp, $name] = $this->incoming($r);
            $kind = in_array($r->string('kind'), ['attachment', 'annex', 'correspondence'], true) ? $r->string('kind') : 'attachment';
            $this->s()->store->storeUpload($this->p(), $id, $tmp, $name, $kind, null, $r->string('visible_external') === '1');
        });
    }

    /** @param array<string,mixed> $params */
    public function import(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", null, function () use ($r, $id): string {
            [$tmp, $name] = $this->incoming($r);
            $res = $this->s()->documents->import($this->p(), $id, $tmp, $name);
            $this->x->session->flash('success', $res['mode'] === 'docx_text'
                ? 'DOCX importiert: Der Text wurde übernommen (Formatierung wird vereinfacht), die Originaldatei ist abgelegt.'
                : 'PDF abgelegt. Hinweis: Der Text des PDF wird nicht ausgelesen (kein OCR); das Original bleibt unverändert die massgebliche Datei.');
            return "/vertraege/{$id}";
        });
    }

    /** @param array<string,mixed> $params */
    public function download(Request $r, array $params): Response
    {
        return $this->s()->store->download($this->p(), $this->id($params));
    }

    /** @param array<string,mixed> $params */
    public function exportPdf(Request $r, array $params): Response
    {
        $d = $this->s()->documents->exportPdf($this->p(), $this->id($params), $r->int('version') ?: null);
        return $this->bytes($d['bytes'], $d['filename'], $d['mime']);
    }

    /** @param array<string,mixed> $params */
    public function exportDocx(Request $r, array $params): Response
    {
        $d = $this->s()->documents->exportDocx($this->p(), $this->id($params), $r->int('version') ?: null);
        return $this->bytes($d['bytes'], $d['filename'], $d['mime']);
    }
}
