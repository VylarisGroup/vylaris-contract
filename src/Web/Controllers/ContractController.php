<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Contracts\StatusMachine;
use App\Web\Controller;

final class ContractController extends Controller
{
    /** @return array<string,mixed> */
    private function filters(Request $r): array
    {
        $f = [];
        foreach (['q', 'status', 'tag', 'sort', 'scope'] as $k) {
            $v = $r->string($k);
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        foreach (['contract_type_id', 'department_id', 'owner_user_id', 'counterparty_id'] as $k) {
            if ($r->int($k) > 0) {
                $f[$k] = $r->int($k);
            }
        }
        foreach (['end_from', 'end_to', 'value_min', 'value_max', 'start_from', 'start_to'] as $k) {
            $v = $r->string($k);
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        return $f;
    }

    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $f = $this->filters($r);
        $result = $this->s()->search->search($p, $f, max(1, $r->int('seite', 1)));
        $tid = $p->tenantId;
        return $this->render($p->isExternal() ? 'portal/contracts' : 'contracts/index', [
            'result' => $result,
            'f' => $f,
            'types' => $tid !== null ? $this->s()->reads->contractTypes($tid) : [],
            'departments' => $tid !== null ? $this->s()->reads->departments($tid) : [],
            'query' => http_build_query(array_diff_key($r->query, ['seite' => 1])),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function exportCsv(Request $r, array $params = []): Response
    {
        $csv = $this->s()->search->exportCsv($this->p(), $this->filters($r));
        return $this->bytes("\xEF\xBB\xBF" . $csv, 'vertraege-' . gmdate('Ymd') . '.csv', 'text/csv; charset=utf-8');
    }

    /** @param array<string,mixed> $params */
    public function suggest(Request $r, array $params = []): Response
    {
        return Response::json(['items' => $this->s()->search->suggest($this->p(), $r->string('q'))]);
    }

    /** @return array<string,mixed> */
    private function formData(?array $c = null): array
    {
        $tid = (int) $this->p()->tenantId;
        return [
            'types' => $this->s()->reads->contractTypes($tid),
            'departments' => $this->s()->reads->departments($tid),
            'members' => $this->s()->reads->members($tid),
            'approverOpts' => $this->s()->reads->members($tid, 'approval.decide'),
            'counterparties' => $this->s()->counterparties->list($this->p()),
            'customFields' => $this->s()->reads->customFields($tid),
            'c' => $c,
        ];
    }

    /** @param array<string,mixed> $params */
    public function createForm(Request $r, array $params = []): Response
    {
        $this->s()->ac->require($this->p(), 'contract.create');
        return $this->render('contracts/form', $this->formData() + ['mode' => 'create', 'parent' => null]);
    }

    /** @return array<string,mixed> */
    private function input(Request $r): array
    {
        $in = [];
        foreach (['title', 'description', 'contract_type_id', 'department_id', 'owner_user_id', 'governing_law', 'venue', 'start_date', 'end_date', 'notice_period_days',
            'renewal_terms', 'renewal_period_days', 'value_amount', 'value_currency', 'payment_terms'] as $k) {
            $in[$k] = $r->string($k);
        }
        if ($in['owner_user_id'] === '') {
            $in['owner_user_id'] = (string) $this->p()->userId;
        }
        $in['auto_renew'] = $r->string('auto_renew') === '1' ? 1 : 0;
        $in['tags'] = array_values(array_filter(array_map('trim', explode(',', $r->string('tags')))));
        $custom = $r->post['custom'] ?? [];
        $in['custom_fields'] = is_array($custom) ? array_map(static fn ($v): string => is_string($v) ? $v : '', $custom) : [];
        $cps = $r->post['party_cp'] ?? [];
        $roles = $r->post['party_role'] ?? [];
        $signs = $r->post['party_sign'] ?? [];
        $parties = [];
        if (is_array($cps)) {
            foreach ($cps as $i => $cp) {
                if (!is_string($cp) || $cp === '') {
                    continue;
                }
                $parties[] = [
                    'counterparty_id' => (int) $cp,
                    'role_label' => is_array($roles) && isset($roles[$i]) && is_string($roles[$i]) ? $roles[$i] : '',
                    'must_sign' => is_array($signs) && isset($signs[$i]) ? 1 : 0,
                    'sign_order' => count($parties) + 1,
                ];
            }
        }
        $in['parties'] = $parties;
        return $in;
    }

    /** @param array<string,mixed> $params */
    public function create(Request $r, array $params = []): Response
    {
        return $this->act($r, '/vertraege/neu', 'Vertrag als Entwurf angelegt.', fn (): string => '/vertraege/' . $this->s()->contracts->create($this->p(), $this->input($r)));
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params): Response
    {
        $p = $this->p();
        $id = $this->id($params);
        $c = $this->s()->contracts->get($p, $id);
        $tid = (int) $c['tenant_id'];
        $data = [
            'c' => $c,
            'documents' => $p->isExternal() || $p->can('document.download') ? $this->s()->store->listFor($p, $id) : [],
            'comments' => $this->s()->comments->list($p, $id),
            'preview' => $this->s()->documents->previewHtml($p, $id),
            'statusLabel' => StatusMachine::allowedFrom((string) $c['status']),
        ];
        if ($p->isExternal()) {
            return $this->render('portal/contract', $data);
        }
        $data += [
            'approvals' => $this->s()->reads->approvalsFor($id, $tid),
            'signatures' => array_map(fn (array $s): array => $s + ['display' => \App\Modules\Signatures\SignatureService::describe($s)], $this->s()->reads->signaturesFor($id, $tid)),
            'deadlines' => $this->s()->deadlines->forContract($p, $id),
            'tasks' => $this->s()->tasks->forContract($p, $id),
            'notary' => $this->s()->reads->notaryCasesFor($id, $tid),
            'holds' => $p->can('legalhold.manage') ? array_values(array_filter($this->s()->holds->list($p, true), static fn (array $h): bool => (int) ($h['contract_id'] ?? 0) === $id)) : [],
            'members' => $this->s()->reads->members($tid),
            'notaries' => $this->s()->reads->notaries($tid),
            'can' => array_combine($acts = ['edit', 'archive', 'export', 'approve', 'request_approval', 'request_signature', 'notary', 'deadline', 'comment', 'download'], array_map(fn (string $a): bool => $this->s()->ac->mayDo($p, $c, $a), $acts)),
            'providerMode' => $this->s()->provider->mode(),
            'providerName' => $this->s()->provider->name(),
            'levels' => $this->s()->provider->supportedLevels(),
            'retention' => $p->can('retention.manage') || $p->can('legalhold.manage') ? $this->s()->retention->evaluate($tid, $id) : null,
        ];
        return $this->render('contracts/show', $data);
    }

    /** @param array<string,mixed> $params */
    public function editForm(Request $r, array $params): Response
    {
        $id = $this->id($params);
        $c = $this->s()->contracts->get($this->p(), $id);
        $this->s()->ac->contract($this->p(), $id, 'edit');
        $custom = $this->s()->reads->customValues($id, (int) $c['tenant_id']);
        $body = $this->s()->reads->currentBodyHtml($id, (int) $c['tenant_id']);
        return $this->render('contracts/form', $this->formData($c) + ['mode' => 'edit', 'parent' => null, 'customValues' => $custom, 'body' => $body]);
    }

    /** @param array<string,mixed> $params */
    public function update(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}/bearbeiten", 'Änderungen gespeichert.', function () use ($r, $id): string {
            $in = $this->input($r);
            $c = $this->s()->ac->contract($this->p(), $id, 'edit');
            if ($c['status'] !== StatusMachine::DRAFT) {
                unset($in['parties']);
            }
            $this->s()->contracts->update($this->p(), $id, $in);
            return "/vertraege/{$id}";
        });
    }

    /** @param array<string,mixed> $params */
    public function saveBody(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}/bearbeiten", 'Vertragstext gespeichert.', function () use ($r, $id): string {
            $this->s()->contracts->saveBody($this->p(), $id, (string) ($r->post['body'] ?? ''), $r->string('change_note') ?: null);
            return "/vertraege/{$id}";
        });
    }

    /** @param array<string,mixed> $params */
    public function archive(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Vertrag archiviert.', fn () => $this->s()->contracts->archive($this->p(), $id, $r->string('reason')));
    }

    /** @param array<string,mixed> $params */
    public function amendment(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Nachtrag als Entwurf angelegt.', fn (): string => '/vertraege/' . $this->s()->contracts->createAmendment($this->p(), $id, $r->string('relation_type', 'amendment'), ['title' => $r->string('title') ?: 'Nachtrag']));
    }

    /** @param array<string,mixed> $params */
    public function comment(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Kommentar gespeichert.', fn () => $this->s()->comments->add($this->p(), $id, $r->string('body'), $r->string('visibility', 'internal')));
    }

    /** @param array<string,mixed> $params */
    public function addDeadline(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Frist angelegt.', function () use ($r, $id): void {
            $this->s()->deadlines->add($this->p(), $id, $r->string('kind', 'custom'), $r->string('due_date'), $r->string('label'), [14, 3], $r->int('responsible_user_id') ?: null);
        });
    }

    /** @param array<string,mixed> $params */
    public function deadlineStatus(Request $r, array $params): Response
    {
        $cid = $r->int('contract_id');
        return $this->act($r, "/vertraege/{$cid}", 'Frist aktualisiert.', fn () => $this->s()->deadlines->setStatus($this->p(), $this->id($params), $r->string('status')));
    }

    /** @param array<string,mixed> $params */
    public function addTask(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Aufgabe angelegt.', fn () => $this->s()->tasks->add($this->p(), $id, $r->string('title'), $r->int('assignee_user_id') ?: null, $r->string('due_date') ?: null));
    }

    /** @param array<string,mixed> $params */
    public function completeTask(Request $r, array $params): Response
    {
        $cid = $r->int('contract_id');
        return $this->act($r, "/vertraege/{$cid}", 'Aufgabe erledigt.', fn () => $this->s()->tasks->complete($this->p(), $this->id($params)));
    }
}
