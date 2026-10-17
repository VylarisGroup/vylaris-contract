<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class DashboardController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        if ($p->isSystemAdmin && $p->tenantId === null) {
            return Response::redirect('/system');
        }
        return $this->render($p->isExternal() ? 'portal/home' : 'dashboard/index', [
            'data' => $this->s()->dashboard->overview($p),
            'an' => $p->isExternal() ? null : $this->s()->dashboard->analytics($p),
            'notary' => $p->userType === 'notary' ? $this->s()->notary->listFor($p) : [],
        ]);
    }

    /** @param array<string,mixed> $params */
    public function exportCsv(Request $r, array $params = []): Response
    {
        return $this->bytes($this->s()->dashboard->analyticsCsv($this->p()), 'auswertung-' . gmdate('Ymd') . '.csv', 'text/csv; charset=utf-8');
    }
}
