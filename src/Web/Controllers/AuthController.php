<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\AuthException;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\TooManyRequestsException;
use App\Core\ValidationException;
use App\Web\Controller;

final class AuthController extends Controller
{
    private function guest(string $tpl, array $data = [], int $status = 200): Response
    {
        return $this->render($tpl, $data, $status, 'layout/guest');
    }

    /** Nur interne Pfade als Rücksprungziel. */
    private function safeNext(Request $r): string
    {
        $n = $r->string('weiter');
        return $n !== '' && str_starts_with($n, '/') && !str_starts_with($n, '//') && !str_contains($n, '\\') && !str_starts_with($n, '/anmelden') ? $n : '/';
    }

    /** @param array<string,mixed> $params */
    public function loginForm(Request $r, array $params = []): Response
    {
        if (is_int($this->x->session->get('uid'))) {
            return Response::redirect('/');
        }
        $msg = $this->x->session->wasExpired() ? 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.' : null;
        return $this->guest('auth/login', ['next' => $this->safeNext($r), 'notice' => $msg]);
    }

    /** @param array<string,mixed> $params */
    public function login(Request $r, array $params = []): Response
    {
        try {
            $res = $this->s()->auth->login($r->string('email'), (string) ($r->post['password'] ?? ''), $r->ip());
        } catch (TooManyRequestsException $e) {
            throw $e;
        } catch (AuthException) {
            $this->x->session->flash('error', 'Anmeldung nicht möglich. Bitte prüfen Sie E-Mail und Passwort. Neue Konten müssen zuerst bestätigt werden.');
            return Response::redirect('/anmelden');
        }
        $this->x->session->regenerate();
        $uid = $res['user_id'];
        if ($res['next'] === 'mfa') {
            $this->x->session->set('pending_uid', $uid);
            $this->x->session->set('pending_next', $this->safeNext($r));
            return Response::redirect('/anmelden/mfa');
        }
        $this->establish($uid, false);
        if ($res['next'] === 'mfa_enroll') {
            $this->x->session->set('mfa_enroll', true);
            $this->x->session->flash('warning', 'Für Ihr Konto ist Mehrfaktor-Authentifizierung vorgeschrieben. Bitte richten Sie sie jetzt ein.');
            return Response::redirect('/konto/mfa');
        }
        return Response::redirect($this->safeNext($r));
    }

    private function establish(int $uid, bool $mfaOk): void
    {
        $s = $this->x->session;
        $s->forget('pending_uid');
        $s->set('uid', $uid);
        $s->set('epoch', $this->s()->auth->sessionEpoch($uid));
        $s->set('mfa_ok', $mfaOk);
        $s->forget('tenant_id');
        $s->forget('mfa_enroll');
    }

    /** Startet eine Anmeldung mit Passkey (ohne Benutzername; die Challenge gilt 5 Minuten und einmal). @param array<string,mixed> $params */
    public function passkeyOptions(Request $r, array $params = []): Response
    {
        if (!$this->s()->passkeys->available()) {
            return Response::json(['ok' => false, 'error' => 'Passkeys sind auf diesem System nicht verfügbar.'], 503);
        }
        try {
            $b = $this->s()->passkeys->beginLogin($r->ip());
        } catch (TooManyRequestsException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 429);
        }
        $this->x->session->set('pk_login', ['c' => $b['challenge'], 'exp' => time() + 300]);
        return Response::json(['ok' => true, 'options' => $b['options']]);
    }

    /** @param array<string,mixed> $params */
    public function passkeyLogin(Request $r, array $params = []): Response
    {
        $state = $this->x->session->get('pk_login');
        $this->x->session->forget('pk_login'); // einmalig
        $cred = json_decode((string) ($r->post['credential'] ?? ''), true);
        if (!is_array($state) || !is_array($cred) || (int) ($state['exp'] ?? 0) < time()) {
            return Response::json(['ok' => false, 'error' => 'Die Anmeldung ist abgelaufen. Bitte erneut versuchen.'], 400);
        }
        try {
            $uid = $this->s()->passkeys->finishLogin($cred, (string) $state['c'], $r->ip());
        } catch (TooManyRequestsException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 429);
        } catch (AuthException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 401);
        }
        $this->x->session->regenerate();
        $this->establish($uid, true); // Passkey mit Benutzerverifizierung ersetzt Passwort und zweiten Faktor
        return Response::json(['ok' => true, 'redirect' => $this->safeNext($r)]);
    }

    /** @param array<string,mixed> $params */
    public function mfaForm(Request $r, array $params = []): Response
    {
        if (!is_int($this->x->session->get('pending_uid'))) {
            return Response::redirect('/anmelden');
        }
        return $this->guest('auth/mfa');
    }

    /** @param array<string,mixed> $params */
    public function mfa(Request $r, array $params = []): Response
    {
        $uid = $this->x->session->get('pending_uid');
        if (!is_int($uid)) {
            return Response::redirect('/anmelden');
        }
        try {
            $this->s()->auth->verifyMfa($uid, (string) ($r->post['code'] ?? ''), $r->ip());
        } catch (TooManyRequestsException $e) {
            throw $e;
        } catch (AuthException $e) {
            $this->x->session->flash('error', $e->getMessage());
            return Response::redirect('/anmelden/mfa');
        }
        $next = (string) $this->x->session->get('pending_next', '/');
        $this->x->session->regenerate();
        $this->establish($uid, true);
        return Response::redirect(str_starts_with($next, '/') ? $next : '/');
    }

    /** @param array<string,mixed> $params */
    public function logout(Request $r, array $params = []): Response
    {
        $uid = $this->x->session->get('uid');
        if (is_int($uid)) {
            $this->s()->audit->log('auth.logout', 'success', null, $uid, 'user', $uid);
        }
        $this->x->session->destroy();
        return Response::redirect('/anmelden');
    }

    /** @param array<string,mixed> $params */
    public function forgotForm(Request $r, array $params = []): Response
    {
        return $this->guest('auth/forgot');
    }

    /** @param array<string,mixed> $params */
    public function forgot(Request $r, array $params = []): Response
    {
        try {
            $this->s()->auth->requestPasswordReset($r->string('email'), $r->ip());
        } catch (TooManyRequestsException $e) {
            throw $e;
        } catch (\Throwable) {
            // bewusst keine Unterscheidung: keine Konto-Enumeration
        }
        $this->x->session->flash('success', 'Falls ein Konto zu dieser Adresse existiert, wurde eine E-Mail mit weiteren Schritten versendet.');
        return Response::redirect('/anmelden');
    }

    /** @param array<string,mixed> $params */
    public function resetForm(Request $r, array $params): Response
    {
        $t = (string) ($params['token'] ?? '');
        if (!$this->s()->auth->resetTokenValid($t)) {
            return $this->guest('errors/message', ['title' => 'Link ungültig', 'text' => 'Der Link ist abgelaufen oder wurde bereits verwendet. Bitte fordern Sie einen neuen an.'], 410);
        }
        return $this->guest('auth/reset', ['token' => $t]);
    }

    /** @param array<string,mixed> $params */
    public function reset(Request $r, array $params): Response
    {
        $t = (string) ($params['token'] ?? '');
        $back = '/passwort/zuruecksetzen/' . rawurlencode($t);
        if (($r->post['password'] ?? '') !== ($r->post['password2'] ?? null)) {
            $this->x->session->putOld([], ['password2' => 'Die Passwörter stimmen nicht überein.']);
            return Response::redirect($back);
        }
        return $this->act($r, $back, 'Passwort geändert. Bitte melden Sie sich an.', function () use ($t, $r): string {
            $this->s()->auth->resetPassword($t, (string) ($r->post['password'] ?? ''));
            return '/anmelden';
        });
    }

    /** @param array<string,mixed> $params */
    public function verifyForm(Request $r, array $params): Response
    {
        return $this->guest('auth/verify', ['token' => (string) ($params['token'] ?? '')]);
    }

    /** @param array<string,mixed> $params */
    public function verify(Request $r, array $params): Response
    {
        try {
            $this->s()->auth->verifyEmail((string) ($params['token'] ?? ''));
        } catch (DomainException $e) {
            return $this->guest('errors/message', ['title' => 'Bestätigung nicht möglich', 'text' => $e->getMessage()], 410);
        }
        return $this->redirect('/anmelden', 'E-Mail-Adresse bestätigt. Sie können sich nun anmelden.');
    }

    /** @param array<string,mixed> $params */
    public function inviteForm(Request $r, array $params): Response
    {
        $t = (string) ($params['token'] ?? '');
        if (!$this->s()->auth->inviteValid($t)) {
            return $this->guest('errors/message', ['title' => 'Einladung ungültig', 'text' => 'Die Einladung ist abgelaufen oder wurde bereits verwendet. Bitten Sie um eine neue Einladung.'], 410);
        }
        return $this->guest('auth/invite', ['token' => $t]);
    }

    /** @param array<string,mixed> $params */
    public function invite(Request $r, array $params): Response
    {
        $t = (string) ($params['token'] ?? '');
        $back = '/einladung/' . rawurlencode($t);
        if (($r->post['password'] ?? '') !== ($r->post['password2'] ?? null)) {
            $this->x->session->putOld([], ['password2' => 'Die Passwörter stimmen nicht überein.']);
            return Response::redirect($back);
        }
        return $this->act($r, $back, 'Konto aktiviert. Bitte melden Sie sich an.', function () use ($t, $r): string {
            $this->s()->auth->acceptInvitation($t, (string) ($r->post['password'] ?? ''));
            return '/anmelden';
        });
    }

    /** @param array<string,mixed> $params */
    public function tenantForm(Request $r, array $params = []): Response
    {
        $uid = (int) $this->x->session->get('uid');
        return $this->render('auth/tenants', ['tenants' => $this->tenants($uid), 'isSys' => (int) ($this->x->user['is_system_admin'] ?? 0) === 1]);
    }

    /** @param array<string,mixed> $params */
    public function tenantSelect(Request $r, array $params = []): Response
    {
        $uid = (int) $this->x->session->get('uid');
        $tid = $r->int('tenant_id', -1);
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $this->tenants($uid));
        $isSys = (int) ($this->x->user['is_system_admin'] ?? 0) === 1;
        if ($tid === 0 && $isSys) {
            $this->x->session->set('tenant_id', 0);
            $this->x->session->regenerate();
            return Response::redirect('/system');
        }
        if (!in_array($tid, $ids, true)) {
            return $this->redirect('/mandant-waehlen', 'Ungültige Auswahl.', 'error');
        }
        $this->x->session->set('tenant_id', $tid);
        $this->x->session->regenerate();
        return Response::redirect('/');
    }

    /** @return list<array<string,mixed>> */
    private function tenants(int $uid): array
    {
        return $this->s()->users->activeTenants($uid);
    }
}
