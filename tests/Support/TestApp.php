<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\FixedClock;
use App\Modules\Admin\SystemAdminService;
use App\Modules\Admin\TenantAdminService;
use App\Modules\Auditor\AuditorService;
use App\Modules\Comments\CommentService;
use App\Modules\Compliance\LegalHoldService;
use App\Modules\Compliance\PrivacyService;
use App\Modules\Compliance\PurgeService;
use App\Modules\Compliance\RetentionService;
use App\Modules\Counterparties\CounterpartyService;
use App\Modules\Mandates\MandateService;
use App\Modules\Notary\NotaryService;
use App\Modules\Approvals\ApprovalService;
use App\Modules\Audit\AuditLogger;
use App\Modules\Contracts\ContractService;
use App\Modules\Contracts\WorkflowService;
use App\Modules\Documents\ContractDocumentService;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\DocxImporter;
use App\Modules\Documents\DocxWriter;
use App\Modules\Documents\PlainPdfRenderer;
use App\Modules\Documents\UploadValidator;
use App\Modules\Deadlines\DeadlineService;
use App\Modules\Deadlines\TaskService;
use App\Modules\Maintenance\CronRunner;
use App\Modules\Notifications\NotificationService;
use App\Modules\Search\DashboardService;
use App\Modules\Search\SearchService;
use App\Modules\Signatures\SandboxProvider;
use App\Modules\Templates\TemplateService;
use App\Modules\Signatures\SignatureService;
use App\Mail\ArrayMailer;
use App\Security\AuthService;
use App\Security\RateLimiter;
use App\Services\AccessControl;
use DateTimeImmutable;
use DateTimeZone;

/** Verdrahtet die Services für Tests (kein DI-Container nötig). */
final class TestApp
{
    public FixedClock $clock;
    public AuditLogger $audit;
    public AccessControl $ac;
    public WorkflowService $workflow;
    public ContractService $contracts;
    public ApprovalService $approvals;
    public Fixtures $fx;
    public DocumentStore $store;
    public ContractDocumentService $documents;
    public SandboxProvider $sandbox;
    public SignatureService $signatures;
    public MandateService $mandates;
    public CommentService $comments;
    public AuditorService $auditor;
    public NotaryService $notary;
    public LegalHoldService $holds;
    public RetentionService $retention;
    public PurgeService $purge;
    public PrivacyService $privacy;
    public CounterpartyService $counterparties;
    public TenantAdminService $tenantAdmin;
    public SystemAdminService $sysAdmin;
    public NotificationService $notifications;
    public DeadlineService $deadlines;
    public TaskService $tasks;
    public CronRunner $cron;
    public SearchService $search;
    public DashboardService $dashboard;
    public TemplateService $templates;
    public ArrayMailer $mailer;
    public AuthService $auth;
    public RateLimiter $limiter;
    public string $storageDir;

    public function __construct(public Database $db, string $now = '2026-06-15 12:00:00')
    {
        $this->storageDir = sys_get_temp_dir() . '/vp_test_' . bin2hex(random_bytes(6));
        register_shutdown_function(fn () => $this->cleanup()); // keine Reste im Temp-Verzeichnis
        $this->clock = new FixedClock(new DateTimeImmutable($now, new DateTimeZone('UTC')));
        $this->audit = new AuditLogger($db, 'test');
        $this->ac = new AccessControl($db, $this->clock, $this->audit);
        $this->workflow = new WorkflowService($db, $this->audit);
        $this->contracts = new ContractService($db, $this->ac, $this->audit, $this->workflow, $this->clock);
        $this->approvals = new ApprovalService($db, $this->ac, $this->audit, $this->workflow);
        $this->fx = new Fixtures($db);
        $validator = new UploadValidator(2_000_000);
        $this->store = new DocumentStore($db, $this->ac, $this->audit, $validator, $this->storageDir);
        $this->documents = new ContractDocumentService($db, $this->ac, $this->audit, $this->contracts, $this->store, $validator, new DocxImporter(), new PlainPdfRenderer(), new DocxWriter());
        $this->mailer = new ArrayMailer();
        $this->notifications = new NotificationService($db, $this->clock, $this->mailer, 'https://test.invalid');
        $this->deadlines = new DeadlineService($db, $this->ac, $this->audit, $this->clock, $this->notifications, $this->workflow);
        $this->tasks = new TaskService($db, $this->ac, $this->audit);
        $this->cron = new CronRunner($db, $this->clock);
        $this->search = new SearchService($db, $this->ac, $this->audit);
        $this->dashboard = new DashboardService($db, $this->ac, $this->clock);
        $this->templates = new TemplateService($db, $this->ac, $this->audit, $this->contracts);
        $this->limiter = new RateLimiter($db, $this->clock);
        $this->auth = new AuthService($db, $this->clock, $this->audit, $this->mailer, $this->limiter, \App\Core\Crypto::generateKey(), 'https://test.invalid');
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
        $this->sandbox = new SandboxProvider();
        $this->signatures = new SignatureService($db, $this->ac, $this->audit, $this->workflow, $this->documents, $this->store, $this->sandbox);
    }

    /** Entfernt den temporären Dokumentenspeicher des Tests. */
    public function cleanup(): void
    {
        if (is_dir($this->storageDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->storageDir);
        }
    }

    /** Schreibt eine temporäre Datei und liefert den Pfad (wird mit dem Speicher aufgeräumt). */
    public function tmpFile(string $bytes): string
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
        $path = $this->storageDir . '/upload_' . bin2hex(random_bytes(5));
        file_put_contents($path, $bytes);
        return $path;
    }

    /** Interner Benutzer mit Rolle im Mandanten. */
    public function actor(int $tenantId, string $role, ?int $departmentId = null): \App\Models\Principal
    {
        $u = $this->fx->user();
        $this->fx->member($tenantId, $u, $role);
        if ($departmentId !== null) {
            $this->fx->departmentMember($tenantId, $departmentId, $u);
        }
        $p = $this->ac->principal($u, $tenantId);
        if ($p === null) {
            throw new \RuntimeException('Principal nicht aufbaubar');
        }
        return $p;
    }

    /** @return array<string,mixed> */
    public function contractInput(array $over = []): array
    {
        return $over + [
            'title' => 'Dienstleistungsvertrag Muster',
            'contract_type_id' => (int) $this->db->value("SELECT id FROM contract_types WHERE code = 'service' AND tenant_id IS NULL"),
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'notice_period_days' => 90,
            'value_amount' => '12500.00',
            'value_currency' => 'CHF',
        ];
    }

    /** Benutzer mit Passwort, bestätigter E-Mail und aktivem Konto. @return array{id:int,email:string} */
    public function loginUser(string $password = 'Korrektes-Pferd-42', string $type = 'internal'): array
    {
        $id = $this->fx->user($type);
        $email = (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$id]);
        $this->db->run('UPDATE users SET password_hash = ?, email_verified_at = UTC_TIMESTAMP(6) WHERE id = ?', [AuthService::hashPassword($password), $id]);
        return ['id' => $id, 'email' => $email];
    }

    /** Vertrag mit Text, geprüft und freigegeben (Status "approved"). */
    public function approvedContract(\App\Models\Principal $mgr, int $tenantId, string $body = '<p>Der Anbieter erbringt Leistungen.</p>'): int
    {
        $appr = $this->actor($tenantId, 'approver');
        $id = $this->contracts->create($mgr, $this->contractInput());
        $this->contracts->saveBody($mgr, $id, $body);
        $vid = $this->approvals->request($mgr, $id, [[$appr->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->approvals->decide($appr, $ap, 'approve');
        return $id;
    }

    /** Aktiver externer Benutzer (Passwort gesetzt) mit Mandatsrolle im Mandanten. @return array{id:int,email:string,principal:\App\Models\Principal} */
    public function externalUser(int $tenantId, string $userType): array
    {
        $u = $this->loginUser('Korrektes-Pferd-42', $userType);
        $this->fx->member($tenantId, $u['id'], $userType === 'external_party' ? 'external_party' : $userType);
        $p = $this->ac->principal($u['id'], null);
        return $u + ['principal' => $p];
    }
}
