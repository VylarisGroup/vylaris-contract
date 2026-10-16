<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\ForbiddenException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Web\Controller;

/** Portale für Notariat und Revisionsstelle. Alle Zugriffe laufen über Mandate (AccessControl). */
final class PortalController extends Controller
{
    private function need(string $type): void
    {
        if ($this->p()->userType !== $type) {
            throw new ForbiddenException();
        }
    }

    /** @param array<string,mixed> $params */
    public function notaryList(Request $r, array $params = []): Response
    {
        $this->need('notary');
        return $this->render('portal/notary_list', ['cases' => $this->s()->notary->listFor($this->p())]);
    }

    /** @param array<string,mixed> $params */
    public function notaryShow(Request $r, array $params): Response
    {
        $this->need('notary');
        return $this->render('portal/notary_show', ['case' => $this->s()->notary->get($this->p(), $this->id($params))]);
    }

    /** @param array<string,mixed> $params */
    public function notaryUpdate(Request $r, array $params): Response
    {
        $this->need('notary');
        $id = $this->id($params);
        return $this->act($r, "/portal/notariat/{$id}", 'Status gesetzt.', fn () => $this->s()->notary->notaryUpdate($this->p(), $id, $r->string('status'), $r->string('remark')));
    }

    /** @param array<string,mixed> $params */
    public function notaryReturn(Request $r, array $params): Response
    {
        $this->need('notary');
        $id = $this->id($params);
        return $this->act($r, "/portal/notariat/{$id}", 'Rückgabedokument gespeichert.', function () use ($r, $id): void {
            $f = $r->files['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || is_array($f['tmp_name'] ?? null) || !is_uploaded_file((string) $f['tmp_name'])) {
                throw new ValidationException(['file' => 'Bitte eine gültige Datei auswählen.']);
            }
            $this->s()->notary->uploadReturn($this->p(), $id, (string) $f['tmp_name'], (string) ($f['name'] ?? 'rueckgabe.pdf'));
        });
    }

    /** @param array<string,mixed> $params */
    public function audit(Request $r, array $params): Response
    {
        $this->need('auditor');
        $id = $this->id($params);
        $p = $this->p();
        return $this->render('portal/audit', [
            'view' => $this->s()->auditor->view($p, $id),
            'history' => $this->s()->auditor->history($p, $id),
            'evidence' => $this->s()->auditor->evidence($p, $id),
            'documents' => $this->s()->store->listFor($p, $id),
            'comments' => $this->s()->comments->list($p, $id),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function annotate(Request $r, array $params): Response
    {
        $this->need('auditor');
        $id = $this->id($params);
        return $this->act($r, "/portal/pruefung/{$id}", 'Prüfvermerk gespeichert.', fn () => $this->s()->auditor->annotate($this->p(), $id, $r->string('text')));
    }

    /** @param array<string,mixed> $params */
    public function report(Request $r, array $params): Response
    {
        $this->need('auditor');
        $out = $this->s()->auditor->exportReport($this->p(), [$this->id($params)]);
        return $this->bytes($out['bytes'], 'pruefbericht-' . substr($out['sha256'], 0, 12) . '.pdf', 'application/pdf');
    }
}
