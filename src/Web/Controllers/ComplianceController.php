<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class ComplianceController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $tid = (int) $p->tenantId;
        return $this->render('compliance/index', [
            'holds' => $p->can('legalhold.manage') ? $this->s()->holds->list($p, false) : [],
            'policies' => $p->can('retention.manage') ? $this->s()->retention->list($p) : [],
            'queue' => $p->can('retention.manage') ? $this->s()->retention->reviewQueue($p) : [],
            'requests' => $p->can('privacy.manage') ? $this->s()->privacy->list($p) : [],
            'types' => $this->s()->reads->contractTypes($tid),
            'counterparties' => $p->can('contract.view') ? $this->s()->counterparties->list($p) : [],
            'contracts' => $this->s()->reads->tenantContractsBrief($tid),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function placeHold(Request $r, array $params = []): Response
    {
        return $this->act($r, '/compliance', 'Rechtliche Sperre gesetzt. Löschung und Aufbewahrungsablauf sind blockiert.', fn () => $this->s()->holds->place($this->p(), $r->int('contract_id') ?: null, $r->int('counterparty_id') ?: null, $r->string('reason')));
    }

    /** @param array<string,mixed> $params */
    public function releaseHold(Request $r, array $params): Response
    {
        return $this->act($r, '/compliance', 'Sperre aufgehoben.', fn () => $this->s()->holds->release($this->p(), $this->id($params), $r->string('reason')));
    }

    /** @param array<string,mixed> $params */
    public function saveRetention(Request $r, array $params = []): Response
    {
        $in = [];
        foreach (['name', 'purpose', 'legal_basis', 'retention_years', 'jurisdiction', 'start_event', 'on_expiry', 'contract_type_id'] as $k) {
            $in[$k] = $r->string($k);
        }
        return $this->act($r, '/compliance', 'Aufbewahrungsregel gespeichert. Sie ist „zu prüfen“, bis eine fachkundige Stelle sie bestätigt.', fn () => $this->s()->retention->save($this->p(), $in, $r->int('id') ?: null));
    }

    /** @param array<string,mixed> $params */
    public function reviewRetention(Request $r, array $params): Response
    {
        return $this->act($r, '/compliance', 'Regel als rechtlich geprüft markiert.', fn () => $this->s()->retention->markReviewed($this->p(), $this->id($params)));
    }

    /** @param array<string,mixed> $params */
    public function purge(Request $r, array $params): Response
    {
        return $this->act($r, '/compliance', 'Vertrag und zugehörige Dokumente wurden unwiderruflich gelöscht; die Löschung ist protokolliert.', fn () => $this->s()->purge->purge($this->p(), $this->id($params), $r->string('confirm_number')));
    }

    /** @param array<string,mixed> $params */
    public function openRequest(Request $r, array $params = []): Response
    {
        return $this->act($r, '/compliance', null, function () use ($r): string {
            $id = $this->s()->privacy->open($this->p(), $r->string('type'), $r->string('subject'), $r->int('counterparty_id') ?: null);
            $this->x->session->flash('success', 'Anfrage erfasst.');
            return "/compliance/anfragen/{$id}";
        });
    }

    /** @param array<string,mixed> $params */
    public function request(Request $r, array $params): Response
    {
        $p = $this->p();
        $id = $this->id($params);
        $req = null;
        foreach ($this->s()->privacy->list($p) as $row) {
            if ((int) $row['id'] === $id) {
                $req = $row;
            }
        }
        if ($req === null) {
            throw new \App\Core\NotFoundException();
        }
        $report = null;
        $blockers = [];
        $note = null;
        try {
            $report = $req['request_type'] === 'access' ? $this->s()->privacy->accessReport($p, $id) : null;
            $blockers = $req['request_type'] === 'erasure' ? $this->s()->privacy->erasureBlockers($p, $id) : [];
        } catch (\App\Core\BusinessRuleException $e) {
            $note = $e->getMessage() . ' Ohne Zuordnung muss die Datengrundlage manuell zusammengestellt werden.';
        }
        return $this->render('compliance/request', ['req' => $req, 'report' => $report, 'blockers' => $blockers, 'note' => $note]);
    }

    /** @param array<string,mixed> $params */
    public function decideRequest(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/compliance/anfragen/{$id}", 'Entscheid gespeichert und protokolliert.', fn () => $this->s()->privacy->decide($this->p(), $id, $r->string('status'), $r->string('reason')));
    }
}
