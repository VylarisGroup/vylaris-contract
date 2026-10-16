<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Maintenance\ChangelogService;
use App\Web\Controller;

/** «Neuigkeiten»: Änderungsverlauf für Administrationen, einmalig nach einem Update. */
final class ChangelogController extends Controller
{
    private function guard(): void
    {
        $p = $this->p();
        if (!($p->isSystemAdmin || $p->can('tenant.manage') || $p->can('user.manage'))) {
            throw new NotFoundException();
        }
    }

    private function safeNext(string $n): string
    {
        return $n !== '' && str_starts_with($n, '/') && !str_starts_with($n, '//') && !str_contains($n, '\\') && !str_starts_with($n, '/neuigkeiten') ? $n : '/';
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params = []): Response
    {
        $this->guard();
        $cur = $this->s()->updates->currentVersion();
        $seen = $this->s()->users->lastSeenVersion($this->p()->userId);
        $new = $seen === false ? [] : $this->s()->changelog->since($seen === null ? null : $seen, $cur);
        $unseen = $seen !== false && ChangelogService::isUnseen($seen, $cur);
        return $this->render('changelog/index', [
            'current' => $cur,
            'unseen' => $unseen && $new !== [],
            'new' => $unseen ? $new : [],
            'older' => array_values(array_filter($this->s()->changelog->entries(), static fn (array $e): bool => version_compare($e['version'], $cur, '<=') && !in_array($e['version'], array_column($unseen ? $new : [], 'version'), true))),
            'next' => $this->safeNext($r->string('weiter')),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function seen(Request $r, array $params = []): Response
    {
        $this->guard();
        $this->s()->users->markVersionSeen($this->p()->userId, $this->s()->updates->currentVersion());
        return Response::redirect($this->safeNext($r->string('weiter')));
    }
}
