<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Web\Controller;

final class AccountController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        return $this->render('account/index', ['profile' => $this->s()->users->profile($this->p()->userId)]);
    }

    /** @param array<string,mixed> $params */
    public function password(Request $r, array $params = []): Response
    {
        if (($r->post['new_password'] ?? '') !== ($r->post['new_password2'] ?? null)) {
            $this->x->session->putOld([], ['new_password2' => 'Die Passwörter stimmen nicht überein.']);
            return Response::redirect('/konto');
        }
        return $this->act($r, '/konto', 'Passwort geändert. Andere Sitzungen wurden beendet.', function () use ($r): void {
            $this->s()->auth->changePassword($this->p()->userId, (string) ($r->post['current_password'] ?? ''), (string) ($r->post['new_password'] ?? ''));
            // Diese Sitzung bleibt gültig, alle anderen werden durch die neue Epoche beendet.
            $this->x->session->set('epoch', $this->s()->auth->sessionEpoch($this->p()->userId));
            $this->x->session->regenerate();
        });
    }

    /** @param array<string,mixed> $params */
    public function mfa(Request $r, array $params = []): Response
    {
        $setup = $this->x->session->get('mfa_setup');
        $recovery = $this->x->session->get('mfa_recovery');
        $this->x->session->forget('mfa_recovery'); // Wiederherstellungscodes werden genau einmal angezeigt
        return $this->render('account/mfa', [
            'enabled' => $this->x->user['mfa_enabled_at'] !== null,
            'setup' => is_array($setup) ? $setup : null,
            'recovery' => $recovery,
            'enrollRequired' => $this->x->session->get('mfa_enroll') === true,
        ]);
    }

    /** @param array<string,mixed> $params */
    public function mfaStart(Request $r, array $params = []): Response
    {
        return $this->act($r, '/konto/mfa', null, function (): void {
            $this->x->session->set('mfa_setup', $this->s()->auth->beginMfaEnrollment($this->p()->userId));
        });
    }

    /** @param array<string,mixed> $params */
    public function mfaConfirm(Request $r, array $params = []): Response
    {
        return $this->act($r, '/konto/mfa', 'Mehrfaktor-Authentifizierung ist aktiv. Bitte bewahren Sie die Wiederherstellungscodes sicher auf.', function () use ($r): void {
            $codes = $this->s()->auth->confirmMfaEnrollment($this->p()->userId, (string) ($r->post['code'] ?? ''));
            $this->x->session->forget('mfa_setup');
            $this->x->session->forget('mfa_enroll');
            $this->x->session->set('mfa_ok', true);
            $this->x->session->set('mfa_recovery', $codes);
            $this->x->session->regenerate();
        });
    }

    /** @param array<string,mixed> $params */
    public function mfaDisable(Request $r, array $params = []): Response
    {
        return $this->act($r, '/konto/mfa', 'Mehrfaktor-Authentifizierung wurde deaktiviert.', function () use ($r): void {
            $this->s()->auth->disableMfa($this->p()->userId, (string) ($r->post['password'] ?? ''));
            $this->x->session->set('mfa_ok', false);
        });
    }

    /** @param array<string,mixed> $params */
    public function logoutAll(Request $r, array $params = []): Response
    {
        $this->s()->auth->logoutEverywhere($this->p()->userId);
        $this->x->session->destroy();
        return Response::redirect('/anmelden');
    }

    /** @param array<string,mixed> $params */
    public function notifications(Request $r, array $params = []): Response
    {
        return $this->render('account/notifications', ['items' => $this->s()->notifications->listFor($this->p(), 100)]);
    }

    /** @param array<string,mixed> $params */
    public function markRead(Request $r, array $params): Response
    {
        $this->s()->notifications->markRead($this->p(), $this->id($params));
        return Response::redirect('/benachrichtigungen');
    }
}
