<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class SystemController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $s = $this->s();
        $migrator = new Migrator($s->db, $this->x->root . '/database/migrations');
        return $this->render('system/index', [
            'tenants' => $s->sysAdmin->tenants($p),
            'diag' => $s->diagnostics->run($migrator),
            'jobs' => $s->cron->status(),
            'verify' => $this->x->session->get('audit_verify'),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function createTenant(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system', 'Mandant angelegt; die erste Administration wurde eingeladen.', fn () => $this->s()->sysAdmin->createTenant($this->p(), $r->string('name'), $r->string('slug'), $r->string('admin_email'), $r->string('admin_name')));
    }

    /** @param array<string,mixed> $params */
    public function tenantStatus(Request $r, array $params): Response
    {
        return $this->act($r, '/system', 'Status des Mandanten geändert.', fn () => $this->s()->sysAdmin->setTenantStatus($this->p(), $this->id($params), $r->string('status')));
    }

    /** @param array<string,mixed> $params */
    public function verifyAudit(Request $r, array $params = []): Response
    {
        $res = $this->s()->audit->verify();
        $this->x->session->set('audit_verify', $res + ['at' => gmdate('Y-m-d H:i:s')]);
        return $this->redirect('/system', $res['ok'] ? 'Audit-Kette konsistent (' . $res['checked'] . ' Einträge).' : 'ACHTUNG: Audit-Kette nicht konsistent – ' . ($res['reason'] ?? ''), $res['ok'] ? 'success' : 'error');
    }

    /** @param array<string,mixed> $params */
    public function updateForm(Request $r, array $params = []): Response
    {
        $u = $this->s()->updates;
        return $this->render('system/update', ['current' => $u->currentVersion(), 'latest' => $u->lastCheck(), 'run' => $u->lastRun(), 'repo' => \App\Modules\Maintenance\UpdateService::REPO]);
    }

    /** @param array<string,mixed> $params */
    public function updateCheck(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/aktualisierung', 'Prüfung abgeschlossen.', fn () => $this->s()->updates->check($this->p()));
    }

    /** @param array<string,mixed> $params */
    public function updateInstall(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/aktualisierung', 'System erfolgreich aktualisiert.', fn () => $this->s()->updates->install($this->p(), $r->string('version')));
    }
}
