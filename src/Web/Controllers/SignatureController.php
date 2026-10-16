<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\ForbiddenException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Signatures\SandboxProvider;
use App\Modules\Signatures\SignerSpec;
use App\Web\Controller;

final class SignatureController extends Controller
{
    /** @param array<string,mixed> $params */
    public function request(Request $r, array $params): Response
    {
        $id = $this->id($params);
        return $this->act($r, "/vertraege/{$id}", 'Signaturvorgang angelegt.', function () use ($r, $id): void {
            $emails = $r->post['signer_email'] ?? [];
            $names = $r->post['signer_name'] ?? [];
            $signers = [];
            if (is_array($emails)) {
                foreach ($emails as $i => $mail) {
                    if (!is_string($mail) || trim($mail) === '') {
                        continue;
                    }
                    $signers[] = new SignerSpec(strtolower(trim($mail)), is_array($names) && is_string($names[$i] ?? null) ? trim($names[$i]) : '', count($signers) + 1);
                }
            }
            $this->s()->signatures->request($this->p(), $id, $r->string('level', 'SES'), $signers, $r->string('message'));
        });
    }

    private function back(Request $r): string
    {
        $cid = $r->int('contract_id');
        return $cid > 0 ? "/vertraege/{$cid}" : '/';
    }

    /** @param array<string,mixed> $params */
    public function refresh(Request $r, array $params): Response
    {
        return $this->act($r, $this->back($r), 'Status beim Anbieter abgefragt.', fn () => $this->s()->signatures->refresh($this->p(), $this->id($params)));
    }

    /** @param array<string,mixed> $params */
    public function cancel(Request $r, array $params): Response
    {
        return $this->act($r, $this->back($r), 'Signaturvorgang zurückgezogen.', fn () => $this->s()->signatures->cancel($this->p(), $this->id($params), $r->string('reason', 'Zurückgezogen')));
    }

    /**
     * Nur im Testmodus: simuliert die Reaktion des (Fake-)Anbieters. Im Livemodus existiert diese Funktion nicht.
     *
     * @param array<string,mixed> $params
     */
    public function simulate(Request $r, array $params): Response
    {
        $prov = $this->s()->provider;
        if (!($prov instanceof SandboxProvider) || $prov->mode() !== 'sandbox') {
            throw new ForbiddenException();
        }
        $this->s()->ac->require($this->p(), 'signature.request');
        $sig = $this->s()->signatures->get($this->p(), $this->id($params)); // prüft Sicht- und Mandantenrecht
        $state = in_array($r->string('state'), ['signed', 'declined', 'expired'], true) ? $r->string('state') : 'signed';
        return $this->act($r, $this->back($r), 'Testmodus: Anbieter-Antwort simuliert. Dies ist KEINE rechtsgültige Signatur.', function () use ($prov, $sig, $state): void {
            $prov->simulate((string) $sig['external_transaction_id'], $state);
            $this->s()->signatures->refresh($this->p(), (int) $sig['id']);
        });
    }
}
