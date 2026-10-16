<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\AuthException;
use App\Core\Config;
use App\Modules\Maintenance\SettingsService;
use App\Core\Database;
use App\Core\DomainException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\TooManyRequestsException;
use App\Web\Controllers as C;

/** Front-Controller-Logik: Bootstrap, Routing, Authentifizierung, CSRF, Fehlerbehandlung. */
final class App
{
    private Router $router;
    private ?Ctx $ctx = null;
    private ?Services $services = null;

    public function __construct(private readonly string $root)
    {
        $this->router = new Router();
    }

    private ?Config $envConfig = null;

    public function run(): void
    {
        $envFile = getenv('APP_ENV_FILE') ?: $this->root . '/.env';
        $config = null;
        if (is_file($envFile)) {
            try {
                $config = Config::fromFile($envFile);
            } catch (\Throwable) {
                $config = null;
            }
        }
        $this->envConfig = $config;
        if ($config !== null && is_file($this->root . '/storage/install.lock')) {
            try {
                $config = SettingsService::overlay($config, Database::connect($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS')));
            } catch (\Throwable) {
                // ohne Datenbank gelten die .env-Werte; handle() meldet den Fehler
            }
        }
        $trusted = $config !== null && $config->has('TRUSTED_PROXIES') ? array_values(array_filter(array_map('trim', explode(',', $config->get('TRUSTED_PROXIES'))))) : [];
        $request = Request::fromGlobals($trusted);
        $response = $this->handle($request, $config);
        $response = SecurityHeaders::apply($response, $request->isHttps());
        $response->send();
    }

    public function handle(Request $request, ?Config $config): Response
    {
        if ($request->path === '/brand.css' && $request->method === 'GET') {
            // Markenfarbe als eigene Stylesheet-Datei (die CSP erlaubt keine Inline-Styles)
            $hex = $config !== null && $config->has('BRAND_COLOR') ? $config->get('BRAND_COLOR') : '#4f46e5';
            $hex = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? $hex : '#4f46e5';
            return new Response(200, ':root{--brand:' . $hex . '}', ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
        }
        $installed = is_file($this->root . '/storage/install.lock') && $config !== null;
        $session = new Session(($config?->get('STORAGE_PATH', $this->root . '/storage') ?? $this->root . '/storage') . '/sessions');
        $view = new View($this->root . '/templates');
        try {
            $session->start($request);
            // Setup-Assistent: nur solange keine Installationssperre existiert.
            if (!$installed) {
                return $this->setup($request, $session, $view);
            }
            if (str_starts_with($request->path, '/setup')) {
                return Response::html('Seite nicht gefunden.', 404);
            }
            $flag = ($config->get('STORAGE_PATH', $this->root . '/storage')) . '/maintenance.flag';
            $maint = is_file($flag) && time() - (int) @filemtime($flag) < 900;
            if ($maint) {
                // Selbstprüfung des Updaters darf während der Wartung durch (Einmal-Token aus dem Flag).
                $fj = json_decode((string) @file_get_contents($flag), true);
                $tok = is_array($fj) ? (string) ($fj['token'] ?? '') : '';
                $sent = (string) ($_SERVER['HTTP_X_UPDATE_CHECK'] ?? '');
                if ($tok !== '' && $sent !== '' && hash_equals($tok, $sent) && in_array($request->path, ['/anmelden', '/assets/app.css'], true)) {
                    $maint = false;
                }
            }
            if ($maint) {
                return new Response(503, '<!doctype html><html lang="de"><meta charset="utf-8"><title>Wartung</title><body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem"><h1>Wartungsarbeiten</h1><p>Das System wird gerade aktualisiert. Bitte in wenigen Minuten erneut versuchen.</p></body></html>', ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '120']);
            }
            $db = Database::connect($config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
            $this->services = new Services($config, $db, $this->root, $this->envConfig);
            $this->services->audit->setContext($request->ip(), $request->correlationId);
            $this->ctx = new Ctx($this->services, $session, $view, $request, $this->root);
            $this->routes();
            if ($request->method === 'POST') {
                $csrfFail = $this->checkCsrf($request, $session);
                if ($csrfFail !== null) {
                    return $csrfFail;
                }
            }
            return $this->router->dispatch($request);
        } catch (\Throwable $e) {
            return $this->error($e, $request, $session, $view);
        }
    }

    private function checkCsrf(Request $r, Session $s): ?Response
    {
        if ($r->post === [] && (int) ($r->server['CONTENT_LENGTH'] ?? 0) > 0 && str_contains((string) ($r->server['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
            return $this->page('errors/message', ['title' => 'Datei zu gross', 'text' => 'Die hochgeladene Datei überschreitet das Limit des Servers (post_max_size / upload_max_filesize in den PHP-Einstellungen). Bitte eine kleinere Datei wählen oder die Betreiber um Anpassung bitten.'], 413);
        }
        $token = (string) ($r->post['_csrf'] ?? $r->server['HTTP_X_CSRF_TOKEN'] ?? '');
        $origin = (string) ($r->server['HTTP_ORIGIN'] ?? '');
        $host = (string) ($r->server['HTTP_HOST'] ?? '');
        $originOk = $origin === '' || parse_url($origin, PHP_URL_HOST) === preg_replace('/:\d+$/', '', $host) || (string) parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === $host;
        if ($token === '' || !hash_equals($s->csrfToken(), $token) || !$originOk) {
            $this->services?->audit->log('security.csrf_rejected', 'denied', null, $s->get('uid') ? (int) $s->get('uid') : null, null, null, ['path' => $r->path]);
            return $this->page('errors/message', ['title' => 'Sitzung abgelaufen', 'text' => 'Das Formular ist abgelaufen oder ungültig. Bitte laden Sie die Seite neu und versuchen Sie es erneut.'], 419);
        }
        return null;
    }

    // ------------------------------------------------------------------ Fehler

    private function error(\Throwable $e, Request $request, Session $session, View $view): Response
    {
        if ($e instanceof TooManyRequestsException) {
            return $this->page('errors/message', ['title' => 'Zu viele Versuche', 'text' => $e->getMessage()], 429)->withHeader('Retry-After', '900');
        }
        if ($e instanceof AuthException) {
            if ($this->ctx !== null && $this->ctx->session->get('uid') === null) {
                return Response::redirect('/anmelden');
            }
            return $this->page('errors/message', ['title' => 'Nicht angemeldet', 'text' => $e->getMessage()], 401);
        }
        if ($e instanceof NotFoundException) {
            return $this->page('errors/message', ['title' => 'Nicht gefunden', 'text' => 'Die Seite oder das Objekt existiert nicht oder ist für Sie nicht sichtbar.'], 404);
        }
        if ($e instanceof ForbiddenException) {
            return $this->page('errors/message', ['title' => 'Kein Zugriff', 'text' => 'Für diese Aktion fehlt Ihnen die Berechtigung.'], 403);
        }
        if ($e instanceof DomainException) {
            return $this->page('errors/message', ['title' => 'Aktion nicht möglich', 'text' => $e->getMessage()], $e->httpStatus() >= 400 && $e->httpStatus() < 500 ? $e->httpStatus() : 400);
        }
        // Unerwartet: Details nur ins Log (ohne Geheimnisse), Nutzer sieht nur die Korrelations-ID.
        $this->logError($e, $request);
        return $this->page('errors/message', [
            'title' => 'Unerwarteter Fehler',
            'text' => 'Es ist ein interner Fehler aufgetreten. Bitte geben Sie bei einer Rückfrage diese Referenz an: ' . $request->correlationId,
        ], 500);
    }

    private function logError(\Throwable $e, Request $r): void
    {
        $dir = $this->root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $msg = preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 400)) ?? '';
        $line = sprintf("[%s] %s %s %s: %s in %s:%d - %s\n", gmdate('c'), $r->correlationId, $r->method, $r->path, $e::class, basename($e->getFile()), $e->getLine(), $msg);
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $data */
    private function page(string $tpl, array $data, int $status): Response
    {
        $view = new View($this->root . '/templates');
        $view->share('ui', new Ui('', [], []));
        $view->share('flash', []);
        $view->share('p', $this->ctx?->p);
        $view->share('user', $this->ctx?->user);
        $view->share('csrf', '');
        $view->share('unread', 0);
        $view->share('path', '');
        $view->share('sandbox', false);
        $view->share('brand', $this->services?->config->get('BRAND_COLOR', '#4f46e5') ?? '#4f46e5');
        return Response::html($view->render($tpl, $data, $this->ctx?->p !== null ? 'layout/app' : 'layout/guest'), $status);
    }

    // ------------------------------------------------------------------ Setup

    private function setup(Request $request, Session $session, View $view): Response
    {
        return (new C\SetupController($session, $view, $this->root))->handle($request);
    }

    // ------------------------------------------------------------------ Routen

    private function routes(): void
    {
        $x = $this->ctx;
        assert($x !== null);
        $r = $this->router;
        $h = static fn (string $cls, string $m): callable => static fn (Request $req, array $params): Response => (new $cls($x))->$m($req, $params);

        $auth = [fn (Request $q, callable $n): Response => $this->mwAuth($q, $n, 'any')];
        $int = [fn (Request $q, callable $n): Response => $this->mwAuth($q, $n, 'internal')];
        $ext = [fn (Request $q, callable $n): Response => $this->mwAuth($q, $n, 'external')];
        $sys = [fn (Request $q, callable $n): Response => $this->mwAuth($q, $n, 'system')];
        $pre = [fn (Request $q, callable $n): Response => $this->mwAuth($q, $n, 'none')];

        // Öffentlich / Anmeldung
        $r->get('/anmelden', $h(C\AuthController::class, 'loginForm'));
        $r->post('/anmelden', $h(C\AuthController::class, 'login'));
        $r->get('/anmelden/mfa', $h(C\AuthController::class, 'mfaForm'));
        $r->post('/anmelden/mfa', $h(C\AuthController::class, 'mfa'));
        $r->post('/anmelden/passkey/optionen', $h(C\AuthController::class, 'passkeyOptions'));
        $r->post('/anmelden/passkey', $h(C\AuthController::class, 'passkeyLogin'));
        $r->post('/abmelden', $h(C\AuthController::class, 'logout'));
        $r->get('/passwort/vergessen', $h(C\AuthController::class, 'forgotForm'));
        $r->post('/passwort/vergessen', $h(C\AuthController::class, 'forgot'));
        $r->get('/passwort/zuruecksetzen/{token}', $h(C\AuthController::class, 'resetForm'));
        $r->post('/passwort/zuruecksetzen/{token}', $h(C\AuthController::class, 'reset'));
        $r->get('/email/bestaetigen/{token}', $h(C\AuthController::class, 'verifyForm'));
        $r->post('/email/bestaetigen/{token}', $h(C\AuthController::class, 'verify'));
        $r->get('/einladung/{token}', $h(C\AuthController::class, 'inviteForm'));
        $r->post('/einladung/{token}', $h(C\AuthController::class, 'invite'));
        $r->get('/mandant-waehlen', $h(C\AuthController::class, 'tenantForm'), $pre);
        $r->post('/mandant-waehlen', $h(C\AuthController::class, 'tenantSelect'), $pre);

        // Konto
        $r->get('/konto', $h(C\AccountController::class, 'index'), $auth);
        $r->get('/konto/passwort', $h(C\AccountController::class, 'passwordForm'), $auth);
        $r->get('/konto/sitzungen', $h(C\AccountController::class, 'sessions'), $auth);
        $r->post('/konto/passwort', $h(C\AccountController::class, 'password'), $auth);
        $r->get('/konto/mfa', $h(C\AccountController::class, 'mfa'), $auth);
        $r->post('/konto/mfa/start', $h(C\AccountController::class, 'mfaStart'), $auth);
        $r->post('/konto/mfa/bestaetigen', $h(C\AccountController::class, 'mfaConfirm'), $auth);
        $r->post('/konto/mfa/deaktivieren', $h(C\AccountController::class, 'mfaDisable'), $auth);
        $r->get('/konto/passkeys', $h(C\AccountController::class, 'passkeys'), $auth);
        $r->post('/konto/passkeys/optionen', $h(C\AccountController::class, 'passkeyOptions'), $auth);
        $r->post('/konto/passkeys/registrieren', $h(C\AccountController::class, 'passkeyRegister'), $auth);
        $r->post('/konto/passkeys/{id}/loeschen', $h(C\AccountController::class, 'passkeyRemove'), $auth);
        $r->post('/konto/alle-abmelden', $h(C\AccountController::class, 'logoutAll'), $auth);
        $r->get('/benachrichtigungen', $h(C\AccountController::class, 'notifications'), $int);
        $r->post('/benachrichtigungen/{id}/gelesen', $h(C\AccountController::class, 'markRead'), $int);

        // Start / Suche
        $r->get('/', $h(C\DashboardController::class, 'index'), $auth);
        $r->get('/vertraege', $h(C\ContractController::class, 'index'), $auth);
        $r->get('/vertraege/export.csv', $h(C\ContractController::class, 'exportCsv'), $int);
        $r->get('/vertraege/vorschlaege', $h(C\ContractController::class, 'suggest'), $int);

        // Verträge (intern)
        $r->get('/vertraege/neu', $h(C\ContractController::class, 'createForm'), $int);
        $r->post('/vertraege/neu', $h(C\ContractController::class, 'create'), $int);
        $r->get('/vertraege/{id}', $h(C\ContractController::class, 'show'), $auth);
        $r->get('/vertraege/{id}/bearbeiten', $h(C\ContractController::class, 'editForm'), $int);
        $r->post('/vertraege/{id}/bearbeiten', $h(C\ContractController::class, 'update'), $int);
        $r->post('/vertraege/{id}/text', $h(C\ContractController::class, 'saveBody'), $int);
        $r->post('/vertraege/{id}/archivieren', $h(C\ContractController::class, 'archive'), $int);
        $r->post('/vertraege/{id}/nachtrag', $h(C\ContractController::class, 'amendment'), $int);
        $r->post('/vertraege/{id}/kommentare', $h(C\ContractController::class, 'comment'), $auth);
        $r->post('/vertraege/{id}/fristen', $h(C\ContractController::class, 'addDeadline'), $int);
        $r->post('/fristen/{id}/status', $h(C\ContractController::class, 'deadlineStatus'), $int);
        $r->post('/vertraege/{id}/aufgaben', $h(C\ContractController::class, 'addTask'), $int);
        $r->post('/aufgaben/{id}/erledigen', $h(C\ContractController::class, 'completeTask'), $int);

        // Dokumente
        $r->post('/vertraege/{id}/dokument', $h(C\DocumentController::class, 'upload'), $int);
        $r->post('/vertraege/{id}/import', $h(C\DocumentController::class, 'import'), $int);
        $r->get('/dokumente/{id}', $h(C\DocumentController::class, 'download'), $auth);
        $r->get('/vertraege/{id}/export/pdf', $h(C\DocumentController::class, 'exportPdf'), $int);
        $r->get('/vertraege/{id}/export/docx', $h(C\DocumentController::class, 'exportDocx'), $int);

        // Freigaben
        $r->get('/freigaben', $h(C\ApprovalController::class, 'index'), $int);
        $r->post('/vertraege/{id}/freigabe', $h(C\ApprovalController::class, 'request'), $int);
        $r->post('/vertraege/{id}/freigabe/zurueckziehen', $h(C\ApprovalController::class, 'withdraw'), $int);
        $r->post('/freigaben/{id}/entscheiden', $h(C\ApprovalController::class, 'decide'), $int);

        // Signatur
        $r->post('/vertraege/{id}/signatur', $h(C\SignatureController::class, 'request'), $int);
        $r->post('/signaturen/{id}/aktualisieren', $h(C\SignatureController::class, 'refresh'), $int);
        $r->post('/signaturen/{id}/abbrechen', $h(C\SignatureController::class, 'cancel'), $int);
        $r->post('/signaturen/{id}/simulieren', $h(C\SignatureController::class, 'simulate'), $int);

        // Vorlagen & Klauseln
        $r->get('/vorlagen', $h(C\TemplateController::class, 'index'), $int);
        $r->get('/vorlagen/neu', $h(C\TemplateController::class, 'createForm'), $int);
        $r->post('/vorlagen/neu', $h(C\TemplateController::class, 'create'), $int);
        $r->get('/vorlagen/{id}', $h(C\TemplateController::class, 'show'), $int);
        $r->post('/vorlagen/{id}/version', $h(C\TemplateController::class, 'addVersion'), $int);
        $r->post('/vorlagen/{id}/pruefung', $h(C\TemplateController::class, 'requestReview'), $int);
        $r->post('/vorlagen/{id}/entscheid', $h(C\TemplateController::class, 'decide'), $int);
        $r->post('/vorlagen/{id}/archivieren', $h(C\TemplateController::class, 'archive'), $int);
        $r->post('/vorlagen/{id}/vorschau', $h(C\TemplateController::class, 'preview'), $int);
        $r->post('/vorlagen/{id}/verwenden', $h(C\TemplateController::class, 'instantiate'), $int);
        $r->get('/klauseln', $h(C\TemplateController::class, 'clauses'), $int);
        $r->post('/klauseln', $h(C\TemplateController::class, 'saveClause'), $int);
        $r->post('/klauseln/{id}/pruefung', $h(C\TemplateController::class, 'clauseReview'), $int);

        // Gegenparteien
        $r->get('/gegenparteien', $h(C\CounterpartyController::class, 'index'), $int);
        $r->get('/gegenparteien/neu', $h(C\CounterpartyController::class, 'form'), $int);
        $r->post('/gegenparteien/neu', $h(C\CounterpartyController::class, 'save'), $int);
        $r->get('/gegenparteien/{id}', $h(C\CounterpartyController::class, 'form'), $int);
        $r->post('/gegenparteien/{id}', $h(C\CounterpartyController::class, 'save'), $int);
        $r->post('/gegenparteien/{id}/archivieren', $h(C\CounterpartyController::class, 'archive'), $int);

        // Verwaltung (Mandant)
        $r->get('/verwaltung', $h(C\AdminController::class, 'index'), $int);
        $r->get('/verwaltung/benutzer', $h(C\AdminController::class, 'users'), $int);
        $r->post('/verwaltung/benutzer', $h(C\AdminController::class, 'invite'), $int);
        $r->post('/verwaltung/benutzer/{id}/rolle', $h(C\AdminController::class, 'setRole'), $int);
        $r->post('/verwaltung/benutzer/{id}/status', $h(C\AdminController::class, 'setStatus'), $int);
        $r->post('/verwaltung/benutzer/{id}/einladung', $h(C\AdminController::class, 'resend'), $int);
        $r->post('/verwaltung/benutzer/{id}/mfa-zuruecksetzen', $h(C\AdminController::class, 'resetMfa'), $int);
        $r->get('/verwaltung/abteilungen', $h(C\AdminController::class, 'departments'), $int);
        $r->post('/verwaltung/abteilungen', $h(C\AdminController::class, 'saveDepartment'), $int);
        $r->post('/verwaltung/abteilungen/{id}/mitglied', $h(C\AdminController::class, 'departmentMember'), $int);
        $r->get('/verwaltung/einstellungen', $h(C\AdminController::class, 'settings'), $int);
        $r->get('/verwaltung/mandant', $h(C\AdminController::class, 'tenantPage'), $int);
        $r->get('/verwaltung/vertragsarten', $h(C\AdminController::class, 'typesPage'), $int);
        $r->get('/verwaltung/felder', $h(C\AdminController::class, 'fieldsPage'), $int);
        $r->post('/verwaltung/einstellungen', $h(C\AdminController::class, 'saveSettings'), $int);
        $r->post('/verwaltung/vertragsarten', $h(C\AdminController::class, 'saveType'), $int);
        $r->post('/verwaltung/felder', $h(C\AdminController::class, 'saveField'), $int);
        $r->get('/verwaltung/mandate', $h(C\AdminController::class, 'mandates'), $int);
        $r->post('/verwaltung/mandate', $h(C\AdminController::class, 'createMandate'), $int);
        $r->post('/verwaltung/mandate/{id}/widerrufen', $h(C\AdminController::class, 'revokeMandate'), $int);
        $r->post('/verwaltung/mandate/{id}/verlaengern', $h(C\AdminController::class, 'extendMandate'), $int);
        $r->get('/verwaltung/protokoll', $h(C\AdminController::class, 'auditLog'), $int);

        // Compliance
        $r->get('/compliance', $h(C\ComplianceController::class, 'index'), $int);
        $r->post('/compliance/sperren', $h(C\ComplianceController::class, 'placeHold'), $int);
        $r->post('/compliance/sperren/{id}/aufheben', $h(C\ComplianceController::class, 'releaseHold'), $int);
        $r->post('/compliance/aufbewahrung', $h(C\ComplianceController::class, 'saveRetention'), $int);
        $r->post('/compliance/aufbewahrung/{id}/geprueft', $h(C\ComplianceController::class, 'reviewRetention'), $int);
        $r->post('/compliance/loeschen/{id}', $h(C\ComplianceController::class, 'purge'), $int);
        $r->post('/compliance/anfragen', $h(C\ComplianceController::class, 'openRequest'), $int);
        $r->get('/compliance/anfragen/{id}', $h(C\ComplianceController::class, 'request'), $int);
        $r->post('/compliance/anfragen/{id}/entscheid', $h(C\ComplianceController::class, 'decideRequest'), $int);

        // Notariat (intern)
        $r->post('/vertraege/{id}/notariat', $h(C\NotaryController::class, 'create'), $int);
        $r->post('/notariat/{id}/bereitstellen', $h(C\NotaryController::class, 'provide'), $int);
        $r->post('/notariat/{id}/intern', $h(C\NotaryController::class, 'internalUpdate'), $int);

        // Portale
        $r->get('/portal/notariat', $h(C\PortalController::class, 'notaryList'), $ext);
        $r->get('/portal/notariat/{id}', $h(C\PortalController::class, 'notaryShow'), $ext);
        $r->post('/portal/notariat/{id}/status', $h(C\PortalController::class, 'notaryUpdate'), $ext);
        $r->post('/portal/notariat/{id}/rueckgabe', $h(C\PortalController::class, 'notaryReturn'), $ext);
        $r->get('/portal/pruefung/{id}', $h(C\PortalController::class, 'audit'), $ext);
        $r->post('/portal/pruefung/{id}/notiz', $h(C\PortalController::class, 'annotate'), $ext);
        $r->get('/portal/pruefung/{id}/bericht', $h(C\PortalController::class, 'report'), $ext);

        // Systemverwaltung
        $r->get('/system', $h(C\SystemController::class, 'index'), $sys);
        $r->get('/system/mandanten', $h(C\SystemController::class, 'tenants'), $sys);
        $r->get('/system/diagnose', $h(C\SystemController::class, 'diagnosis'), $sys);
        $r->get('/system/audit-kette', $h(C\SystemController::class, 'auditChain'), $sys);
        $r->get('/system/aufgaben', $h(C\SystemController::class, 'jobs'), $sys);
        $r->post('/system/mandanten', $h(C\SystemController::class, 'createTenant'), $sys);
        $r->post('/system/mandanten/{id}/status', $h(C\SystemController::class, 'tenantStatus'), $sys);
        $r->get('/system/aktualisierung', $h(C\SystemController::class, 'updateForm'), $sys);
        $r->post('/system/aktualisierung/pruefen', $h(C\SystemController::class, 'updateCheck'), $sys);
        $r->post('/system/aktualisierung/rechte', $h(C\SystemController::class, 'updateRepair'), $sys);
        $r->post('/system/aktualisierung/installieren', $h(C\SystemController::class, 'updateInstall'), $sys);
        $r->get('/system/einstellungen', $h(C\SystemController::class, 'settingsIndex'), $sys);
        $r->get('/system/einstellungen/{page}', $h(C\SystemController::class, 'settingsForm'), $sys);
        $r->post('/system/einstellungen/testmail', $h(C\SystemController::class, 'mailTest'), $sys);
        $r->post('/system/einstellungen/{group}', $h(C\SystemController::class, 'settingsSave'), $sys);
        $r->get('/system/sicherungen', $h(C\SystemController::class, 'backups'), $sys);
        $r->post('/system/sicherungen', $h(C\SystemController::class, 'backupCreate'), $sys);
        $r->get('/system/sicherungen/{name}', $h(C\SystemController::class, 'backupDownload'), $sys);
        $r->post('/system/sicherungen/{name}/pruefen', $h(C\SystemController::class, 'backupVerify'), $sys);
        $r->post('/system/sicherungen/{name}/loeschen', $h(C\SystemController::class, 'backupDelete'), $sys);
        $r->post('/system/cron', $h(C\SystemController::class, 'cronRun'), $sys);
        $r->post('/system/pruefen', $h(C\SystemController::class, 'verifyAudit'), $sys);
    }

    // ------------------------------------------------------------------ Authentifizierung

    private const ENROLL_ALLOWED = ['/konto/mfa', '/konto/mfa/start', '/konto/mfa/bestaetigen', '/abmelden'];

    /** @param callable(Request):Response $next */
    private function mwAuth(Request $req, callable $next, string $kind): Response
    {
        $x = $this->ctx;
        assert($x !== null);
        $uid = $x->session->get('uid');
        if (!is_int($uid)) {
            return Response::redirect('/anmelden' . ($req->method === 'GET' && $req->path !== '/' ? '?weiter=' . rawurlencode($req->path) : ''));
        }
        $epoch = $x->s->auth->sessionEpoch($uid);
        if ($epoch === null || $epoch !== (int) $x->session->get('epoch')) {
            $x->session->destroy();
            return Response::redirect('/anmelden');
        }
        $user = $x->s->users->sessionUser($uid);
        if ($user === null) {
            $x->session->destroy();
            return Response::redirect('/anmelden');
        }
        $x->user = $user;
        if ($x->session->get('mfa_enroll') === true && !in_array($req->path, self::ENROLL_ALLOWED, true)) {
            return Response::redirect('/konto/mfa');
        }
        $mfaOk = $x->session->get('mfa_ok') === true;
        $tenantId = null;
        if ($user['user_type'] === 'internal') {
            $tenants = $x->s->users->activeTenants($uid);
            $chosen = $x->session->get('tenant_id');
            $ids = array_map(static fn (array $t): int => (int) $t['id'], $tenants);
            $isSys = (int) $user['is_system_admin'] === 1;
            if ($chosen === 0 && $isSys) {
                $tenantId = null;
            } elseif (is_int($chosen) && in_array($chosen, $ids, true)) {
                $tenantId = $chosen;
            } elseif (count($ids) === 1 && !$isSys) {
                $tenantId = $ids[0];
                $x->session->set('tenant_id', $tenantId);
            } elseif ($ids === [] && $isSys) {
                $tenantId = null;
                $x->session->set('tenant_id', 0);
            } else {
                if ($kind === 'none' || $req->path === '/abmelden') {
                    return $next($req);
                }
                return Response::redirect('/mandant-waehlen');
            }
        }
        if ($kind === 'none') {
            return $next($req);
        }
        $p = $x->s->ac->principal($uid, $tenantId, $mfaOk);
        if ($p === null) {
            $x->session->destroy();
            return Response::redirect('/anmelden');
        }
        $x->p = $p;
        if ($kind === 'internal' && ($p->isExternal() || $p->tenantId === null)) {
            return $p->isExternal() ? Response::redirect('/') : Response::redirect('/system');
        }
        if ($kind === 'external' && !$p->isExternal()) {
            throw new NotFoundException();
        }
        if ($kind === 'system' && !($p->isSystemAdmin && $p->tenantId === null)) {
            throw new NotFoundException();
        }
        return $next($req);
    }
}
