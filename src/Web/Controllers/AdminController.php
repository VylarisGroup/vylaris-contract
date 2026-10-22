<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Mandates\MandateService;
use App\Web\Controller;

final class AdminController extends Controller
{
    private const ROLES = ['tenant_admin' => 'Mandantenadministration', 'contract_manager' => 'Vertragsverwaltung', 'approver' => 'Genehmigungsstelle', 'reader' => 'Lesender Zugriff'];

    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $this->s()->ac->require($this->p(), 'tenant.manage');
        $db = $this->s()->db;
        $t = (int) $this->p()->tenantId;
        $n = static fn (string $sql): int => (int) $db->value($sql, [$t]);
        $stats = [
            'users' => $n("SELECT COUNT(DISTINCT user_id) FROM tenant_memberships WHERE tenant_id = ? AND status = 'active'"),
            'departments' => $n('SELECT COUNT(*) FROM departments WHERE tenant_id = ?'),
            'types' => $n('SELECT COUNT(*) FROM contract_types WHERE tenant_id = ?'),
            'fields' => $n('SELECT COUNT(*) FROM custom_field_definitions WHERE tenant_id = ?'),
            'templates' => $n('SELECT COUNT(*) FROM contract_templates WHERE tenant_id = ? AND archived_at IS NULL'),
            'connections' => (int) $db->value("SELECT COUNT(*) FROM tenant_connections WHERE status = 'active' AND (requester_tenant_id = ? OR target_tenant_id = ?)", [$t, $t]),
            'mandates' => $n("SELECT COUNT(*) FROM external_mandates WHERE tenant_id = ? AND status = 'active'"),
            'sso' => $n('SELECT COUNT(*) FROM tenant_sso WHERE tenant_id = ? AND enabled = 1'),
            'retention' => $n('SELECT COUNT(*) FROM retention_policies WHERE tenant_id = ?'),
            'approvers' => count($this->s()->reads->members($t, 'approval.decide')),
        ];
        return $this->render('admin/index', ['st' => $stats]);
    }

    /** @param array<string,mixed> $params */
    public function users(Request $r, array $params = []): Response
    {
        $p = $this->p();
        return $this->render('admin/users', ['members' => $this->s()->tenantAdmin->members($p), 'roles' => self::ROLES, 'departments' => $this->s()->tenantAdmin->departments($p)]);
    }

    /** @param array<string,mixed> $params */
    public function invite(Request $r, array $params = []): Response
    {
        $dep = $r->post['departments'] ?? [];
        return $this->act($r, '/verwaltung/benutzer', 'Einladung versendet bzw. Benutzer hinzugefügt.', fn () => $this->s()->tenantAdmin->invite(
            $this->p(),
            $r->string('email'),
            $r->string('display_name'),
            $r->string('role'),
            is_array($dep) ? array_map('intval', $dep) : [],
        ));
    }

    /** @param array<string,mixed> $params */
    public function setRole(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/benutzer', 'Rolle geändert. Bestehende Sitzungen des Benutzers wurden beendet.', fn () => $this->s()->tenantAdmin->setRole($this->p(), $this->id($params), $r->string('role')));
    }

    /** @param array<string,mixed> $params */
    public function setStatus(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/benutzer', 'Status geändert.', fn () => $this->s()->tenantAdmin->setMembershipStatus($this->p(), $this->id($params), $r->string('status')));
    }

    /** @param array<string,mixed> $params */
    public function resend(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/benutzer', 'Einladung erneut versendet.', fn () => $this->s()->tenantAdmin->resendInvitation($this->p(), $this->id($params)));
    }

    /** @param array<string,mixed> $params */
    public function resetMfa(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/benutzer', 'Zweiter Faktor (TOTP und Passkeys) zurückgesetzt.', fn () => $this->s()->tenantAdmin->resetMfa($this->p(), $this->id($params)));
    }

    /** @param array<string,mixed> $params */
    public function departments(Request $r, array $params = []): Response
    {
        $p = $this->p();
        return $this->render('admin/departments', ['departments' => $this->s()->tenantAdmin->departments($p), 'members' => $this->s()->reads->members((int) $p->tenantId)]);
    }

    /** @param array<string,mixed> $params */
    public function saveDepartment(Request $r, array $params = []): Response
    {
        return $this->act($r, '/verwaltung/abteilungen', 'Abteilung gespeichert.', fn () => $this->s()->tenantAdmin->saveDepartment($this->p(), $r->string('name'), $r->int('id') ?: null));
    }

    /** @param array<string,mixed> $params */
    public function departmentMember(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/abteilungen', 'Zuordnung gespeichert.', fn () => $this->s()->tenantAdmin->setDepartmentMember($this->p(), $this->id($params), $r->int('user_id'), $r->string('member') === '1'));
    }

    /** @param array<string,mixed> $params */
    public function settings(Request $r, array $params = []): Response
    {
        return Response::redirect('/verwaltung/mandant');
    }

    /** @param array<string,mixed> $params */
    public function tenantPage(Request $r, array $params = []): Response
    {
        return $this->render('admin/tenant', ['settings' => $this->s()->tenantAdmin->settings($this->p())]);
    }

    /** @param array<string,mixed> $params */
    public function typesPage(Request $r, array $params = []): Response
    {
        return $this->render('admin/types', ['types' => $this->s()->reads->contractTypes((int) $this->p()->tenantId)]);
    }

    /** @param array<string,mixed> $params */
    public function fieldsPage(Request $r, array $params = []): Response
    {
        return $this->render('admin/fields', ['fields' => $this->s()->reads->customFields((int) $this->p()->tenantId)]);
    }

    /** @param array<string,mixed> $params */
    public function saveSettings(Request $r, array $params = []): Response
    {
        $in = [];
        foreach (['name', 'timezone', 'jurisdiction_default', 'contract_number_prefix'] as $k) {
            $in[$k] = $r->string($k);
        }
        $in['mfa_required'] = 0;
        return $this->act($r, '/verwaltung/mandant', 'Einstellungen gespeichert.', fn () => $this->s()->tenantAdmin->saveSettings($this->p(), $in));
    }

    /** @param array<string,mixed> $params */
    public function saveType(Request $r, array $params = []): Response
    {
        return $this->act($r, '/verwaltung/vertragsarten', 'Vertragsart gespeichert.', fn () => $this->s()->tenantAdmin->saveContractType($this->p(), strtolower($r->string('code')), $r->string('name')));
    }

    /** @param array<string,mixed> $params */
    public function saveField(Request $r, array $params = []): Response
    {
        $opts = array_values(array_filter(array_map('trim', explode(',', $r->string('options')))));
        return $this->act($r, '/verwaltung/felder', 'Feld gespeichert.', fn () => $this->s()->tenantAdmin->saveCustomField($this->p(), strtolower($r->string('code')), $r->string('label'), $r->string('field_type', 'text'), $r->string('required') === '1', $opts));
    }

    /** @param array<string,mixed> $params */
    public function mandates(Request $r, array $params = []): Response
    {
        $p = $this->p();
        return $this->render('admin/mandates', [
            'mandates' => $this->s()->mandates->list($p),
            'types' => MandateService::TYPES,
            'members' => $this->s()->reads->members((int) $p->tenantId),
            'contracts' => $this->s()->reads->tenantContractsBrief((int) $p->tenantId),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function createMandate(Request $r, array $params = []): Response
    {
        return $this->act($r, '/verwaltung/mandate', 'Mandat angelegt; die Person wurde per E-Mail eingeladen bzw. informiert.', function () use ($r): void {
            $type = $r->string('type');
            $actions = $r->post['actions'] ?? [];
            $ids = $r->post['contract_ids'] ?? [];
            $this->s()->mandates->create(
                $this->p(),
                $type,
                $r->string('email'),
                $r->string('display_name'),
                $r->string('organization') ?: null,
                is_array($ids) ? array_map('intval', $ids) : [],
                is_array($actions) ? array_values(array_filter($actions, 'is_string')) : [],
                $r->string('valid_from'),
                $r->string('valid_until'),
                $r->int('responsible_user_id') ?: null,
            );
        });
    }

    /** @param array<string,mixed> $params */
    public function revokeMandate(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/mandate', 'Mandat widerrufen; der Zugriff ist sofort beendet.', fn () => $this->s()->mandates->revoke($this->p(), $this->id($params), $r->string('reason')));
    }

    /** @param array<string,mixed> $params */
    public function extendMandate(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/mandate', 'Mandat verlängert.', fn () => $this->s()->mandates->extend($this->p(), $this->id($params), $r->string('valid_until')));
    }

    /** @param array<string,mixed> $params */
    public function auditLog(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $this->s()->ac->require($p, 'audit.view');
        $page = max(1, $r->int('seite', 1));
        return $this->render('admin/audit', [
            'events' => $this->s()->reads->auditEvents((int) $p->tenantId, 50, ($page - 1) * 50, $r->string('aktion')),
            'page' => $page,
            'action' => $r->string('aktion'),
        ]);
    }
}
