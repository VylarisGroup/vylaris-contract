<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\SystemClock;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;
use App\Mail\UnconfiguredMailer;
use App\Modules\Admin\SystemAdminService;
use App\Modules\Admin\TenantAdminService;
use App\Modules\Approvals\ApprovalService;
use App\Modules\Audit\AuditLogger;
use App\Modules\Auditor\AuditorService;
use App\Modules\Comments\CommentService;
use App\Modules\Compliance\LegalHoldService;
use App\Modules\Compliance\PrivacyService;
use App\Modules\Compliance\PurgeService;
use App\Modules\Compliance\RetentionService;
use App\Modules\Contracts\ContractService;
use App\Modules\Contracts\WorkflowService;
use App\Modules\Counterparties\CounterpartyService;
use App\Modules\Deadlines\DeadlineService;
use App\Modules\Deadlines\TaskService;
use App\Modules\Documents\ContractDocumentService;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\DocxImporter;
use App\Modules\Documents\DocxWriter;
use App\Modules\Documents\PlainPdfRenderer;
use App\Modules\Documents\UploadValidator;
use App\Modules\Maintenance\CronRunner;
use App\Modules\Mandates\MandateService;
use App\Modules\Notary\NotaryService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Search\DashboardService;
use App\Modules\Search\SearchService;
use App\Modules\Signatures\CurlTransport;
use App\Modules\Signatures\SandboxProvider;
use App\Modules\Signatures\SignatureProvider;
use App\Modules\Signatures\SignatureService;
use App\Modules\Signatures\SkribbleProvider;
use App\Modules\Templates\TemplateService;
use App\Security\AuthService;
use App\Security\RateLimiter;
use App\Services\AccessControl;

/** Verdrahtung aller Dienste (einfacher, expliziter Container ohne Magie). */
final class Services
{
    public readonly Clock $clock;
    public readonly AuditLogger $audit;
    public readonly AccessControl $ac;
    public readonly WorkflowService $workflow;
    public readonly ContractService $contracts;
    public readonly ApprovalService $approvals;
    public readonly DocumentStore $store;
    public readonly ContractDocumentService $documents;
    public readonly SignatureProvider $provider;
    public readonly SignatureService $signatures;
    public readonly MandateService $mandates;
    public readonly CommentService $comments;
    public readonly AuditorService $auditor;
    public readonly NotaryService $notary;
    public readonly LegalHoldService $holds;
    public readonly RetentionService $retention;
    public readonly PurgeService $purge;
    public readonly PrivacyService $privacy;
    public readonly CounterpartyService $counterparties;
    public readonly TenantAdminService $tenantAdmin;
    public readonly SystemAdminService $sysAdmin;
    public readonly NotificationService $notifications;
    public readonly DeadlineService $deadlines;
    public readonly TaskService $tasks;
    public readonly CronRunner $cron;
    public readonly SearchService $search;
    public readonly DashboardService $dashboard;
    public readonly TemplateService $templates;
    public readonly Mailer $mailer;
    public readonly AuthService $auth;
    public readonly RateLimiter $limiter;
    public readonly \App\Security\PasskeyService $passkeys;
    public readonly \App\Repositories\UserRepository $users;
    public readonly \App\Repositories\ContractReadRepository $reads;
    public readonly \App\Modules\Maintenance\Diagnostics $diagnostics;
    public readonly \App\Modules\Maintenance\UpdateService $updates;
    public readonly string $storageRoot;

    public readonly Config $envConfig;
    public readonly \App\Modules\Maintenance\SettingsService $settings;

    /** @param ?Config $envConfig nur die .env-Werte (ohne in der Oberfläche gespeicherte); $config ist die wirksame Konfiguration */
    public function __construct(public readonly Config $config, public readonly Database $db, string $root, ?Config $envConfig = null)
    {
        $this->envConfig = $envConfig ?? $config;
        $appKey = $config->get('APP_KEY');
        $baseUrl = rtrim($config->get('APP_URL'), '/');
        $this->storageRoot = $config->get('STORAGE_PATH', $root . '/storage');
        $this->clock = new SystemClock();
        $this->reads = new \App\Repositories\ContractReadRepository($db);
        $this->users = new \App\Repositories\UserRepository($db);
        $this->audit = new AuditLogger($db, hash('sha256', 'audit-ip|' . $appKey));
        $this->ac = new AccessControl($db, $this->clock, $this->audit);
        $this->workflow = new WorkflowService($db, $this->audit);
        $this->contracts = new ContractService($db, $this->ac, $this->audit, $this->workflow, $this->clock);
        $this->approvals = new ApprovalService($db, $this->ac, $this->audit, $this->workflow);
        $validator = new UploadValidator($config->int('MAX_UPLOAD_MB', 20) * 1_000_000);
        $this->store = new DocumentStore($db, $this->ac, $this->audit, $validator, $this->storageRoot . '/private');
        $this->documents = new ContractDocumentService($db, $this->ac, $this->audit, $this->contracts, $this->store, $validator, new DocxImporter(), new PlainPdfRenderer(), new DocxWriter());
        $this->mailer = self::mailer($config);
        $this->notifications = new NotificationService($db, $this->clock, $this->mailer, $baseUrl);
        $this->deadlines = new DeadlineService($db, $this->ac, $this->audit, $this->clock, $this->notifications, $this->workflow);
        $this->tasks = new TaskService($db, $this->ac, $this->audit);
        $this->cron = new CronRunner($db, $this->clock);
        $this->search = new SearchService($db, $this->ac, $this->audit);
        $this->dashboard = new DashboardService($db, $this->ac, $this->clock);
        $this->templates = new TemplateService($db, $this->ac, $this->audit, $this->contracts);
        $this->limiter = new RateLimiter($db, $this->clock);
        $this->auth = new AuthService($db, $this->clock, $this->audit, $this->mailer, $this->limiter, $appKey, $baseUrl, $config->get('APP_NAME', 'Vertragsplattform'));
        $this->mandates = new MandateService($db, $this->ac, $this->audit, $this->clock, $this->auth);
        $this->comments = new CommentService($db, $this->ac, $this->audit);
        $this->auditor = new AuditorService($db, $this->ac, $this->audit, new PlainPdfRenderer());
        $this->notary = new NotaryService($db, $this->ac, $this->audit, $this->documents, $this->store, $this->mandates);
        $this->holds = new LegalHoldService($db, $this->ac, $this->audit);
        $this->retention = new RetentionService($db, $this->ac, $this->audit, $this->clock, $this->holds);
        $this->purge = new PurgeService($db, $this->ac, $this->audit, $this->retention, $this->store);
        $this->privacy = new PrivacyService($db, $this->ac, $this->audit, $this->holds, $this->retention);
        $this->counterparties = new CounterpartyService($db, $this->ac, $this->audit);
        $this->tenantAdmin = new TenantAdminService($db, $this->ac, $this->audit, $this->auth);
        $this->sysAdmin = new SystemAdminService($db, $this->audit, $this->auth);
        $this->provider = self::provider($config, $db);
        $this->signatures = new SignatureService($db, $this->ac, $this->audit, $this->workflow, $this->documents, $this->store, $this->provider);
        $this->diagnostics = new \App\Modules\Maintenance\Diagnostics($db, $config, $root, $this->provider->name(), $this->provider->mode(), $this->storageRoot);
        $this->passkeys = new \App\Security\PasskeyService($db, $this->clock, $this->audit, $this->limiter, \App\Security\WebAuthn::fromAppUrl($config->get('APP_URL', ''), $config->get('APP_NAME', 'Vertragsplattform')), $appKey);
        $this->settings = new \App\Modules\Maintenance\SettingsService($db, $this->audit, $appKey);
        $this->updates = new \App\Modules\Maintenance\UpdateService($db, $this->audit, $root, $this->storageRoot, null, function () use ($db, $config): string {
            $pass = $config->get('BACKUP_PASSPHRASE', '');
            return (new \App\Modules\Maintenance\BackupService($db, $this->storageRoot))->create($this->storageRoot . '/backups', $pass === '' ? null : $pass)['path'];
        }, null, null, (string) $config->get('APP_URL', ''));
    }

    private static function mailer(Config $c): Mailer
    {
        $host = $c->get('SMTP_HOST', '');
        if ($host === '') {
            return new UnconfiguredMailer();
        }
        return new SmtpMailer(
            $host,
            $c->int('SMTP_PORT', 465),
            $c->get('SMTP_ENCRYPTION', 'tls'),
            $c->get('SMTP_USER', ''),
            $c->get('SMTP_PASS', ''),
            $c->get('MAIL_FROM', 'noreply@localhost.invalid'),
            $c->get('MAIL_FROM_NAME', 'Vertragsplattform'),
        );
    }

    private static function provider(Config $c, Database $db): SignatureProvider
    {
        if ($c->get('SIGNATURE_PROVIDER', 'sandbox') === 'skribble') {
            return new SkribbleProvider(
                new CurlTransport(),
                $c->get('SKRIBBLE_BASE_URL'),
                $c->get('SKRIBBLE_USER'),
                $c->get('SKRIBBLE_KEY'),
                $c->get('SKRIBBLE_MODE', 'sandbox'),
            );
        }
        return new SandboxProvider($db);
    }
}
