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
        return $this->render('account/index', ['profile' => $this->s()->users->profile($this->p()->userId), 'passkeyCount' => $this->s()->passkeys->count($this->p()->userId)]);
    }

    /** @param array<string,mixed> $params */
    public function passwordForm(Request $r, array $params = []): Response
    {
        return $this->render('account/password', []);
    }

    /** @param array<string,mixed> $params */
    public function sessions(Request $r, array $params = []): Response
    {
        return $this->render('account/sessions', []);
    }

    /** @param array<string,mixed> $params */
    public function password(Request $r, array $params = []): Response
    {
        if (($r->post['new_password'] ?? '') !== ($r->post['new_password2'] ?? null)) {
            $this->x->session->putOld([], ['new_password2' => 'Die Passwörter stimmen nicht überein.']);
            return Response::redirect('/konto/passwort');
        }
        return $this->act($r, '/konto/passwort', 'Passwort geändert. Andere Sitzungen wurden beendet.', function () use ($r): void {
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
    public function passkeys(Request $r, array $params = []): Response
    {
        return $this->render('account/passkeys', ['available' => $this->s()->passkeys->available(), 'items' => $this->s()->passkeys->list($this->p()->userId)]);
    }

    /** @param array<string,mixed> $params */
    public function passkeyOptions(Request $r, array $params = []): Response
    {
        try {
            $b = $this->s()->passkeys->beginRegistration($this->p()->userId, (string) ($r->post['password'] ?? ''));
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'error' => (string) (array_values($e->errors)[0] ?? 'Eingabe ungültig.')], 422);
        } catch (\App\Core\TooManyRequestsException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 429);
        }
        $this->x->session->set('pk_reg', ['c' => $b['challenge'], 'exp' => time() + 300]);
        return Response::json(['ok' => true, 'options' => $b['options']]);
    }

    /** @param array<string,mixed> $params */
    public function passkeyRegister(Request $r, array $params = []): Response
    {
        $state = $this->x->session->get('pk_reg');
        $this->x->session->forget('pk_reg');
        $cred = json_decode((string) ($r->post['credential'] ?? ''), true);
        if (!is_array($state) || !is_array($cred) || (int) ($state['exp'] ?? 0) < time()) {
            return Response::json(['ok' => false, 'error' => 'Die Einrichtung ist abgelaufen. Bitte erneut starten.'], 400);
        }
        try {
            $this->s()->passkeys->finishRegistration($this->p()->userId, $cred, (string) $state['c'], (string) ($r->post['name'] ?? ''), $r->ip());
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'error' => (string) (array_values($e->errors)[0] ?? 'Eingabe ungültig.')], 422);
        }
        $this->x->session->flash('success', 'Passkey hinzugefügt. Sie können sich nun damit anmelden.');
        return Response::json(['ok' => true, 'redirect' => '/konto/passkeys']);
    }

    /** @param array<string,mixed> $params */
    public function passkeyRemove(Request $r, array $params): Response
    {
        return $this->act($r, '/konto/passkeys', 'Passkey entfernt.', fn () => $this->s()->passkeys->remove($this->p()->userId, $this->id($params)));
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
