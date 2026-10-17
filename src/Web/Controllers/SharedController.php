<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

/** Von verbundenen Mandanten geteilte Verträge (nur lesen). */
final class SharedController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        return $this->render('shared/index', ['items' => $this->s()->shared->list($this->p())]);
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params): Response
    {
        return $this->render('shared/show', ['c' => $this->s()->shared->get($this->p(), $this->id($params))]);
    }

    /** @param array<string,mixed> $params */
    public function document(Request $r, array $params): Response
    {
        $d = $this->s()->shared->document($this->p(), $this->id($params), $this->id($params, 'doc'));
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $d['filename']) ?: 'dokument';
        return new Response(200, $d['bytes'], [
            'Content-Type' => $d['mime'],
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
