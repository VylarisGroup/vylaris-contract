<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\DomainException;
use App\Core\ForbiddenException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Models\Principal;

abstract class Controller
{
    public function __construct(protected Ctx $x)
    {
    }

    protected function s(): Services
    {
        return $this->x->s;
    }

    protected function p(): Principal
    {
        if ($this->x->p === null) {
            throw new ForbiddenException();
        }
        return $this->x->p;
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200, string $layout = 'layout/app'): Response
    {
        [$old, $errors] = $this->x->session->takeOld();
        $ui = new Ui($this->x->session->csrfToken(), $old, $errors);
        $this->x->view->share('ui', $ui);
        $this->x->view->share('flash', $this->x->session->takeFlash());
        $this->x->view->share('p', $this->x->p);
        $this->x->view->share('user', $this->x->user);
        $this->x->view->share('csrf', $this->x->session->csrfToken());
        $this->x->view->share('unread', $this->safeUnread());
        $this->x->view->share('path', $this->x->req->path);
        $this->x->view->share('sandbox', $this->s()->provider->mode() === 'sandbox');
        return Response::html($this->x->view->render($template, $data, $layout), $status);
    }

    private function safeUnread(): int
    {
        try {
            return $this->x->p !== null && $this->x->p->tenantId !== null ? $this->s()->notifications->unreadCount($this->x->p) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function redirect(string $to, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            $this->x->session->flash($type, $flash);
        }
        return Response::redirect($to);
    }

    /**
     * Führt eine Schreibaktion aus. Erfolg: Meldung + Weiterleitung. Validierungsfehler: Eingaben und Feldfehler
     * werden für das Formular zwischengespeichert. Fachliche Fehler: sichere Meldung, Rücksprung.
     *
     * @param callable():(string|null|void) $fn darf eine abweichende Ziel-URL zurückgeben
     */
    protected function act(Request $r, string $backTo, ?string $success, callable $fn, ?string $successTo = null): Response
    {
        try {
            $res = $fn();
            return $this->redirect(is_string($res) && $res !== '' ? $res : ($successTo ?? $backTo), $success);
        } catch (ValidationException $e) {
            $this->x->session->putOld($this->safeOld($r->post), $e->errors);
            $msgs = array_slice(array_values(array_unique(array_map('strval', $e->errors))), 0, 3);
            $this->x->session->flash('error', 'Bitte die Eingaben prüfen' . ($msgs !== [] ? ': ' . implode(' ', $msgs) : '.'));
            return Response::redirect($backTo);
        } catch (DomainException $e) {
            if ($e instanceof \App\Core\NotFoundException || $e instanceof ForbiddenException) {
                throw $e;
            }
            $this->x->session->putOld($this->safeOld($r->post), []);
            $this->x->session->flash('error', $e->getMessage());
            return Response::redirect($backTo);
        }
    }

    /** Passwörter und Tokens landen nie im Zwischenspeicher. @param array<string,mixed> $post @return array<string,mixed> */
    private function safeOld(array $post): array
    {
        $out = [];
        foreach ($post as $k => $v) {
            if (is_string($v) && !str_contains(strtolower((string) $k), 'pass') && !str_contains((string) $k, '_csrf') && !str_contains((string) $k, 'code')) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $params */
    protected function id(array $params, string $key = 'id'): int
    {
        $v = $params[$key] ?? '';
        if (!is_string($v) || preg_match('/^\d{1,18}$/', $v) !== 1) {
            throw new \App\Core\NotFoundException();
        }
        return (int) $v;
    }

    /** Sendet Bytes als Download (nur aus Diensten, die Rechte bereits geprüft haben). */
    protected function bytes(string $bytes, string $filename, string $mime): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'dokument';
        return new Response(200, $bytes, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
