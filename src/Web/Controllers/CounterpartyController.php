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
        return $this->render('counterparties/index', ['items' => $this->s()->counterparties->list($this->p(), $r->string('q')), 'q' => $r->string('q')]);
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
