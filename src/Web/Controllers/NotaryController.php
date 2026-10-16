<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class NotaryController extends Controller
{
    /** @param array<string,mixed> $params */
    public function create(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Notariatsfall angelegt (noch nicht bereitgestellt).', fn () => $this->s()->notary->create($this->p(), $id, $r->int('notary_user_id'), $r->string('office'), $r->int('responsible_user_id') ?: null, $r->string('remarks')));
    }

    /** @param array<string,mixed> $params */
    public function provide(Request $r, array $params): Response
    {
        $cid = $r->int('contract_id');
        return $this->act($r, "/vertraege/{$cid}", 'Dokument dem Notariat bereitgestellt.', fn () => $this->s()->notary->provide($this->p(), $this->id($params)));
    }

    /** @param array<string,mixed> $params */
    public function internalUpdate(Request $r, array $params): Response
    {
        $cid = $r->int('contract_id');
        return $this->act($r, "/vertraege/{$cid}", 'Status gesetzt.', fn () => $this->s()->notary->internalUpdate($this->p(), $this->id($params), $r->string('status'), $r->string('remark')));
    }
}
