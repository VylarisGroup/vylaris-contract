<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class TemplateController extends Controller
{
    /**
     * Felddefinition als Textzeilen: code|Beschriftung|typ|pflicht(1/0)|option1,option2
     *
     * @return list<array<string,mixed>>
     */
    private function parseSchema(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $c = array_map('trim', explode('|', $line));
            $out[] = [
                'code' => $c[0] ?? '',
                'label' => $c[1] ?? '',
                'type' => ($c[2] ?? '') !== '' ? $c[2] : 'text',
                'required' => ($c[3] ?? '0') === '1',
                'options' => ($c[4] ?? '') !== '' ? array_map('trim', explode(',', $c[4])) : [],
            ];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $schema */
    private function schemaText(array $schema): string
    {
        $lines = [];
        foreach ($schema as $f) {
            $lines[] = implode('|', [$f['code'], $f['label'], $f['type'], !empty($f['required']) ? '1' : '0', implode(',', (array) ($f['options'] ?? []))]);
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        return $this->render('templates/index', ['templates' => $this->s()->templates->list($this->p(), $r->string('archiv') === '1'), 'archived' => $r->string('archiv') === '1']);
    }

    /** @param array<string,mixed> $params */
    public function createForm(Request $r, array $params = []): Response
    {
        return $this->render('templates/form', ['types' => $this->s()->reads->contractTypes((int) $this->p()->tenantId), 'schemaText' => '']);
    }

    /** @param array<string,mixed> $params */
    public function create(Request $r, array $params = []): Response
    {
        return $this->act($r, '/vorlagen/neu', 'Vorlage angelegt (noch nicht fachlich geprüft).', fn (): string => '/vorlagen/' . $this->s()->templates->create(
            $this->p(),
            $r->string('name'),
            $r->int('contract_type_id'),
            strtoupper($r->string('jurisdiction', 'CH')),
            (string) ($r->post['body'] ?? ''),
            $this->parseSchema((string) ($r->post['fields'] ?? '')),
        ));
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params): Response
    {
        $p = $this->p();
        $t = $this->s()->templates->get($p, $this->id($params));
        return $this->render('templates/show', [
            't' => $t,
            'schemaText' => $this->schemaText($t['current']['field_schema']),
            'preview' => null,
            'values' => [],
            'types' => $this->s()->reads->contractTypes((int) $p->tenantId),
            'departments' => $this->s()->reads->departments((int) $p->tenantId),
            'clauses' => $this->s()->templates->listClauses($p),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function addVersion(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vorlagen/{$id}", 'Neue Vorlagenversion gespeichert; eine erneute Prüfung ist nötig.', fn () => $this->s()->templates->addVersion($this->p(), $id, (string) ($r->post['body'] ?? ''), $this->parseSchema((string) ($r->post['fields'] ?? ''))));
    }

    /** @param array<string,mixed> $params */
    public function requestReview(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vorlagen/{$id}", 'Zur fachlichen Prüfung eingereicht.', fn () => $this->s()->templates->requestReview($this->p(), $id));
    }

    /** @param array<string,mixed> $params */
    public function decide(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vorlagen/{$id}", 'Entscheid gespeichert.', fn () => $this->s()->templates->decideReview($this->p(), $id, $r->string('decision') === 'approve', $r->string('comment')));
    }

    /** @param array<string,mixed> $params */
    public function archive(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vorlagen/{$id}", 'Vorlage archiviert.', fn () => $this->s()->templates->archive($this->p(), $id), '/vorlagen');
    }

    /** @return array<string,string> */
    private function values(Request $r): array
    {
        $v = $r->post['v'] ?? [];
        return is_array($v) ? array_map(static fn ($x): string => is_string($x) ? $x : '', $v) : [];
    }

    /** @param array<string,mixed> $params */
    public function preview(Request $r, array $params): Response
    {
        $p = $this->p();
        $id = $this->id($params);
        $t = $this->s()->templates->get($p, $id);
        $pv = $this->s()->templates->preview($p, $id, $this->values($r));
        return $this->render('templates/show', [
            't' => $t, 'schemaText' => $this->schemaText($t['current']['field_schema']), 'preview' => $pv, 'values' => $this->values($r),
            'types' => $this->s()->reads->contractTypes((int) $p->tenantId), 'departments' => $this->s()->reads->departments((int) $p->tenantId),
            'clauses' => $this->s()->templates->listClauses($p),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function instantiate(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vorlagen/{$id}", null, function () use ($r, $id): string {
            $in = ['title' => $r->string('title'), 'department_id' => $r->string('department_id'), 'start_date' => $r->string('start_date'), 'end_date' => $r->string('end_date')];
            $res = $this->s()->templates->instantiate($this->p(), $id, $in, $this->values($r), $r->string('allow_unreviewed') === '1');
            $this->x->session->flash($res['warnings'] === [] ? 'success' : 'warning', 'Vertragsentwurf aus Vorlage erstellt.' . ($res['warnings'] !== [] ? ' Hinweise: ' . implode(' ', $res['warnings']) : ''));
            return '/vertraege/' . $res['contract_id'];
        });
    }

    /** @param array<string,mixed> $params */
    public function clauses(Request $r, array $params = []): Response
    {
        return $this->render('templates/clauses', ['clauses' => $this->s()->templates->listClauses($this->p())]);
    }

    /** @param array<string,mixed> $params */
    public function saveClause(Request $r, array $params = []): Response
    {
        return $this->act($r, '/klauseln', 'Klausel gespeichert (Prüfstatus: offen).', fn () => $this->s()->templates->saveClause($this->p(), strtoupper($r->string('code')), $r->string('title'), (string) ($r->post['body'] ?? ''), $r->int('clause_id') ?: null));
    }

    /** @param array<string,mixed> $params */
    public function clauseReview(Request $r, array $params): Response
    {
        return $this->act($r, '/klauseln', 'Prüfstatus gesetzt.', fn () => $this->s()->templates->setClauseReview($this->p(), $this->id($params), $r->string('status')));
    }
}
