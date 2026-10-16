<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Signatures\InternalSigningService;
use App\Web\Controller;

/**
 * Öffentliche Unterzeichnungsseite (persönlicher Link, keine Anmeldung). Schutz: 256-Bit-Token (nur Hash gespeichert),
 * Einmalcode per E-Mail, Begrenzung der Versuche, keine Weitergabe der Token im Referrer, keine Zwischenspeicherung.
 */
final class SigningController extends Controller
{
    private function page(string $tpl, array $data, int $status = 200): Response
    {
        // same-origin statt no-referrer: Chrome sendet bei no-referrer «Origin: null», was der CSRF-Herkunftsprüfung widerspricht; an Dritte wird nichts übermittelt.
        return $this->render($tpl, $data, $status, 'layout/guest')->withHeader('Referrer-Policy', 'same-origin')->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $params */
    public function show(Request $r, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $x = $this->s()->signing->lookup($token);
        if ($x === null) {
            return $this->page('signing/invalid', [], 404);
        }
        $g = $x['signer'];
        $s = $x['session'];
        $canSign = $s['state'] === 'open' && $g['status'] === 'invited';
        if ($canSign) {
            $this->s()->signing->markViewed((int) $g['id']);
        }
        $codeActive = $g['otp_hash'] !== null && $g['otp_expires_at'] > gmdate('Y-m-d H:i:s.u');
        return $this->page('signing/show', [
            'token' => $token, 'session' => $s, 'signer' => $g, 'canSign' => $canSign, 'codeActive' => $codeActive,
            'signers' => $this->s()->signing->publicSigners((int) $s['id']),
            'consent' => InternalSigningService::CONSENT_TEXT,
        ]);
    }

    /** @param array<string,mixed> $params */
    public function document(Request $r, array $params): Response
    {
        $x = $this->s()->signing->lookup((string) ($params['token'] ?? ''));
        if ($x === null) {
            throw new NotFoundException();
        }
        $bytes = $this->s()->signing->documentFor($x['session']);
        if ($x['signer']['status'] === 'invited') {
            $this->s()->signing->markViewed((int) $x['signer']['id']);
        }
        $disp = $r->string('download') === '1' ? 'attachment' : 'inline';
        return new Response(200, $bytes, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disp . '; filename="dokument.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    /** @param array<string,mixed> $params */
    public function code(Request $r, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        return $this->act($r, '/unterschreiben/' . $token, 'Der Code wurde an Ihre E-Mail-Adresse gesendet (15 Minuten gültig).', fn () => $this->s()->signing->sendCode($token));
    }

    /** @param array<string,mixed> $params */
    public function sign(Request $r, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        return $this->act($r, '/unterschreiben/' . $token, 'Vielen Dank – Ihre Unterschrift wurde festgehalten.', fn () => $this->s()->signing->sign(
            $token,
            (string) ($r->post['code'] ?? ''),
            (string) ($r->post['name'] ?? ''),
            ($r->post['consent'] ?? '') === '1',
            $r->ip(),
            (string) ($r->server['HTTP_USER_AGENT'] ?? ''),
        ));
    }

    /** @param array<string,mixed> $params */
    public function decline(Request $r, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        return $this->act($r, '/unterschreiben/' . $token, 'Sie haben die Unterzeichnung abgelehnt. Die absendende Stelle wird informiert.', fn () => $this->s()->signing->decline($token, (string) ($r->post['reason'] ?? ''), $r->ip()));
    }
}
