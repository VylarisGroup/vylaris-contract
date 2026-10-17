<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class CounterpartyController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $admin = $p->can('tenant.manage');
        return $this->render('counterparties/index', [
            'items' => $this->s()->counterparties->list($p, $r->string('q')),
            'q' => $r->string('q'),
            'ov' => $this->s()->connections->overview($p),
            'myCode' => $admin ? $this->s()->connections->myCode($p) : null,
            'sharedCount' => count($this->s()->shared->list($p)),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function neu(Request $r, array $params = []): Response
    {
        return $this->redirect('/gegenparteien#verbinden');
    }

    /** @param array<string,mixed> $params */
    public function connect(Request $r, array $params = []): Response
    {
        return $this->act($r, '/gegenparteien#verbinden', 'Anfrage gesendet. Sobald die Gegenseite bestätigt, erscheint sie hier als Gegenpartei.', fn () => $this->s()->connections->request($this->p(), $r->string('code'), $r->string('message')));
    }

    /** @param array<string,mixed> $params */
    public function newCode(Request $r, array $params = []): Response
    {
        return $this->act($r, '/gegenparteien#verbinden', 'Neuer Mandanten-Code erzeugt. Bestehende Verbindungen bleiben unverändert.', fn () => $this->s()->connections->regenerate($this->p()));
    }

    /** @param array<string,mixed> $params */
    public function decide(Request $r, array $params): Response
    {
        $id = $this->id($params);
        $accept = $r->string('decision') === 'accept';
        return $this->act($r, '/gegenparteien#verbinden', $accept ? 'Verbindung bestätigt.' : 'Anfrage abgelehnt.', fn () => $this->s()->connections->decide($this->p(), $id, $accept));
    }

    /** @param array<string,mixed> $params */
    public function end(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, '/gegenparteien#verbinden', 'Verbindung beendet. Geteilte Verträge sind nicht mehr sichtbar.', fn () => $this->s()->connections->revoke($this->p(), $id));
    }

    /** @param array<string,mixed> $params */
    public function withdraw(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, '/gegenparteien#verbinden', 'Anfrage zurückgezogen.', fn () => $this->s()->connections->withdraw($this->p(), $id));
    }

    /** @param array<string,mixed> $params */
    public function form(Request $r, array $params = []): Response
    {
        $cp = isset($params['id']) ? $this->s()->counterparties->get($this->p(), $this->id($params)) : null;
        return $this->render('counterparties/form', ['cp' => $cp]);
    }

    /** @param array<string,mixed> $params */
    public function save(Request $r, array $params = []): Response
    {
        $id = isset($params['id']) ? $this->id($params) : null;
        $in = [];
        foreach (['party_type', 'name', 'legal_form', 'registration_id', 'contact_email', 'address_line', 'postal_code', 'city', 'country'] as $k) {
            $in[$k] = $r->string($k);
        }
        $in['sensitive_data'] = $r->string('sensitive_data') === '1' ? 1 : 0;
        $back = $id === null ? '/gegenparteien/neu' : "/gegenparteien/{$id}";
        return $this->act($r, $back, 'Gespeichert.', function () use ($in, $id): string {
            $newId = $this->s()->counterparties->save($this->p(), $in, $id);
            return '/gegenparteien/' . $newId;
        });
    }

    /** @param array<string,mixed> $params */
    public function archive(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/gegenparteien/{$id}", 'Gegenpartei archiviert.', fn () => $this->s()->counterparties->archive($this->p(), $id), '/gegenparteien');
    }
}
