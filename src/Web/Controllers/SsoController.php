<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\TooManyRequestsException;
use App\Security\Oidc\OidcException;
use App\Web\Controller;

/** Anmeldung über den Identitätsanbieter des Mandanten (OIDC) und deren Einrichtung. */
final class SsoController extends Controller
{
    private function guest(string $tpl, array $data = [], int $status = 200): Response
    {
        return $this->render($tpl, $data, $status, 'layout/guest');
    }

    private function fail(string $msg): Response
    {
        $this->x->session->flash('error', $msg);
        return Response::redirect('/anmelden');
    }

    /** Beginn mit Kürzel oder E-Mail-Adresse. Die Weiterleitung zum Anbieter erfolgt über einen Link (die CSP verbietet Formular-Weiterleitungen nach aussen). @param array<string,mixed> $params */
    public function start(Request $r, array $params = []): Response
    {
        $tid = $this->s()->sso->tenantFor($r->string('hint'));
        if ($tid === null) {
            return $this->fail('Für diese Eingabe ist keine SSO-Anmeldung eingerichtet. Prüfen Sie das Kürzel Ihres Mandanten oder melden Sie sich mit Passwort an.');
        }
        return $this->begin($tid, $r, false);
    }

    /** @param array<string,mixed> $params */
    public function direct(Request $r, array $params): Response
    {
        $tid = $this->s()->sso->tenantFor((string) ($params['slug'] ?? ''));
        if ($tid === null) {
            return $this->fail('Für diesen Mandanten ist keine SSO-Anmeldung eingerichtet.');
        }
        return $this->begin($tid, $r, true);
    }

    private function begin(int $tid, Request $r, bool $redirect): Response
    {
        try {
            $b = $this->s()->sso->begin($tid, $this->s()->baseUrl, $r->ip(), '/');
        } catch (TooManyRequestsException $e) {
            throw $e;
        } catch (OidcException $e) {
            return $this->fail('Die SSO-Anmeldung ist zurzeit nicht möglich: ' . $e->getMessage());
        }
        $this->x->session->set('oidc', $b['state']);
        if ($redirect) {
            return new Response(303, '', ['Location' => $b['url']]);
        }
        return $this->guest('auth/sso_weiter', ['url' => $b['url']]);
    }

    /** @param array<string,mixed> $params */
    public function callback(Request $r, array $params = []): Response
    {
        $saved = $this->x->session->get('oidc');
        $this->x->session->forget('oidc'); // einmalig
        if ($r->string('error') !== '') {
            return $this->fail('Der Identitätsanbieter hat die Anmeldung nicht bestätigt.');
        }
        try {
            $res = $this->s()->sso->finish(is_array($saved) ? $saved : [], $r->string('state'), $r->string('code'), $this->s()->baseUrl, $r->ip());
        } catch (TooManyRequestsException $e) {
            throw $e;
        } catch (OidcException $e) {
            return $this->fail($e->getMessage());
        }
        $s = $this->x->session;
        $s->regenerate();
        $s->set('uid', $res['user_id']);
        $s->set('epoch', $this->s()->auth->sessionEpoch($res['user_id']));
        $s->set('mfa_ok', true); // Der Identitätsanbieter des Mandanten ist für die Stärke der Anmeldung verantwortlich
        $s->set('sso_tenant', $res['tenant_id']);
        $s->set('tenant_id', $res['tenant_id']);
        $s->forget('pending_uid');
        $s->forget('mfa_enroll');
        $s->set('changelog_check', true);
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------ Einrichtung

    /** @param array<string,mixed> $params */
    public function settings(Request $r, array $params = []): Response
    {
        return $this->render('admin/sso', [
            'c' => $this->s()->sso->config($this->p()),
            'identities' => $this->s()->sso->identities($this->p()),
            'redirectUri' => $this->s()->sso->redirectUri($this->s()->baseUrl),
            'slug' => (string) $this->s()->db->value('SELECT slug FROM tenants WHERE id = ?', [$this->p()->tenantId]),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function save(Request $r, array $params = []): Response
    {
        $in = [];
        foreach (['issuer', 'client_id', 'client_secret', 'default_role', 'allowed_domains', 'enabled', 'auto_provision'] as $k) {
            $in[$k] = $r->string($k);
        }
        return $this->act($r, '/verwaltung/sso', 'SSO-Einstellungen gespeichert.', fn () => $this->s()->sso->save($this->p(), $in));
    }

    /** @param array<string,mixed> $params */
    public function remove(Request $r, array $params = []): Response
    {
        return $this->act($r, '/verwaltung/sso', 'SSO entfernt. Die Anmeldung mit Passwort bleibt unverändert.', fn () => $this->s()->sso->remove($this->p()));
    }

    /** @param array<string,mixed> $params */
    public function unlink(Request $r, array $params): Response
    {
        return $this->act($r, '/verwaltung/sso', 'Verknüpfung gelöst.', fn () => $this->s()->sso->unlink($this->p(), $this->id($params)));
    }
}
