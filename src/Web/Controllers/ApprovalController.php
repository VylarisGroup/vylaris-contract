<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class ApprovalController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        return $this->render('approvals/index', ['items' => $this->s()->approvals->openFor($this->p())]);
    }

    /** @param array<string,mixed> $params */
    public function request(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Freigabe angefordert. Die Version ist nun gesperrt.', function () use ($r, $id): void {
            $steps = [];
            foreach ([1, 2, 3] as $n) {
                $raw = $r->post["step{$n}"] ?? [];
                $ids = is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw)))) : [];
                if ($ids !== []) {
                    $steps[] = $ids;
                }
            }
            $this->s()->contracts->reopenForApproval($this->p(), $id); // abgelehnt/zurückgezogen -> neuer Entwurf mit gleichem Text
            $this->s()->approvals->request($this->p(), $id, $steps);
        });
    }

    /** @param array<string,mixed> $params */
    public function withdraw(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Freigabeantrag zurückgezogen.', fn () => $this->s()->approvals->withdraw($this->p(), $id, $r->string('reason')));
    }

    /** @param array<string,mixed> $params */
    public function decide(Request $r, array $params): Response
    {
        $cid = $r->int('contract_id');
        $back = $cid > 0 ? "/vertraege/{$cid}" : '/freigaben';
        return $this->act($r, $back, null, function () use ($r, $params, $back): string {
            $out = $this->s()->approvals->decide($this->p(), $this->id($params), $r->string('decision'), $r->string('comment'));
            $this->x->session->flash('success', match ($out) {
                'approved' => 'Freigegeben. Der Vertrag ist vollständig genehmigt.',
                'rejected' => 'Abgelehnt. Der Vertrag wurde zur Überarbeitung zurückgegeben.',
                default => 'Entscheid gespeichert. Weitere Freigaben stehen noch aus.',
            });
            return $back;
        });
    }
}
