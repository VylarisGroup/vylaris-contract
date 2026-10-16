<?php

declare(strict_types=1);

namespace App\Web\Controllers;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\Migrator;
use App\Modules\Maintenance\BackupService;
use App\Modules\Maintenance\CronTasks;
use App\Modules\Maintenance\SettingsService;
use App\Core\Request;
use App\Core\Response;
use App\Web\Controller;

final class SystemController extends Controller
{
    /** @param array<string,mixed> $params */
    public function index(Request $r, array $params = []): Response
    {
        $p = $this->p();
        $s = $this->s();
        $migrator = new Migrator($s->db, $this->x->root . '/database/migrations');
        return $this->render('system/index', [
            'tenants' => $s->sysAdmin->tenants($p),
            'diag' => $s->diagnostics->run($migrator),
            'jobs' => $s->cron->status(),
            'verify' => $this->x->session->get('audit_verify'),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function createTenant(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system', 'Mandant angelegt; die erste Administration wurde eingeladen.', fn () => $this->s()->sysAdmin->createTenant($this->p(), $r->string('name'), $r->string('slug'), $r->string('admin_email'), $r->string('admin_name')));
    }

    /** @param array<string,mixed> $params */
    public function tenantStatus(Request $r, array $params): Response
    {
        return $this->act($r, '/system', 'Status des Mandanten geändert.', fn () => $this->s()->sysAdmin->setTenantStatus($this->p(), $this->id($params), $r->string('status')));
    }

    /** @param array<string,mixed> $params */
    public function verifyAudit(Request $r, array $params = []): Response
    {
        $res = $this->s()->audit->verify();
        $this->x->session->set('audit_verify', $res + ['at' => gmdate('Y-m-d H:i:s')]);
        return $this->redirect('/system', $res['ok'] ? 'Audit-Kette konsistent (' . $res['checked'] . ' Einträge).' : 'ACHTUNG: Audit-Kette nicht konsistent – ' . ($res['reason'] ?? ''), $res['ok'] ? 'success' : 'error');
    }

    /** @param array<string,mixed> $params */
    public function updateForm(Request $r, array $params = []): Response
    {
        $u = $this->s()->updates;
        return $this->render('system/update', ['current' => $u->currentVersion(), 'latest' => $u->lastCheck(), 'run' => $u->lastRun(), 'repo' => \App\Modules\Maintenance\UpdateService::REPO]);
    }

    /** @param array<string,mixed> $params */
    public function updateCheck(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/aktualisierung', 'Prüfung abgeschlossen.', fn () => $this->s()->updates->check($this->p()));
    }

    /** @param array<string,mixed> $params */
    public function updateInstall(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/aktualisierung', 'System erfolgreich aktualisiert.', fn () => $this->s()->updates->install($this->p(), $r->string('version')));
    }

    // ------------------------------------------------------------ Einstellungen

    /** @param array<string,mixed> $params */
    public function settingsForm(Request $r, array $params = []): Response
    {
        $s = $this->s();
        return $this->render('system/settings', [
            'items' => $s->settings->view($s->envConfig),
            'phpLimits' => ['upload' => ini_get('upload_max_filesize'), 'post' => ini_get('post_max_size')],
            'backupPassSet' => $s->config->get('BACKUP_PASSPHRASE', '') !== '',
            'mailActive' => $s->config->get('SMTP_HOST', '') !== '',
        ]);
    }

    /** @param array<string,mixed> $params */
    public function settingsSave(Request $r, array $params): Response
    {
        $group = (string) ($params['group'] ?? '');
        $post = $r->post;
        $reset = is_array($post['reset'] ?? null) ? $post['reset'] : [];
        return $this->act($r, '/system/einstellungen', 'Einstellungen gespeichert. Sie gelten ab sofort.', function () use ($group, $post, $reset): void {
            $this->s()->settings->save($this->p(), $group, $post, $reset, $this->s()->envConfig);
        });
    }

    /** @param array<string,mixed> $params */
    public function mailTest(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/einstellungen', null, function (): void {
            $p = $this->p();
            if (!$p->isSystemAdmin) {
                throw new ForbiddenException();
            }
            $u = $this->s()->db->one('SELECT email, display_name FROM users WHERE id = ?', [$p->userId]);
            if ($u === null || $this->s()->config->get('SMTP_HOST', '') === '') {
                throw new BusinessRuleException('Es ist kein SMTP-Server eingetragen.');
            }
            try {
                $this->s()->mailer->send((string) $u['email'], (string) $u['display_name'], 'Testnachricht der Vertragsplattform', "Diese Nachricht bestätigt, dass der E-Mail-Versand funktioniert.\n\nGesendet am " . gmdate('d.m.Y H:i') . ' UTC.');
            } catch (\Throwable $e) {
                $this->s()->audit->log('system.mail_test', 'failure', null, $p->userId);
                throw new BusinessRuleException('Versand fehlgeschlagen: ' . mb_substr($e->getMessage(), 0, 200));
            }
            $this->s()->audit->log('system.mail_test', 'success', null, $p->userId);
            $this->x->session->flash('success', 'Testnachricht an ' . $u['email'] . ' gesendet.');
        });
    }

    // ------------------------------------------------------------ Sicherungen

    private function backupDir(): string
    {
        return $this->s()->storageRoot . '/backups';
    }

    private function backupPass(): ?string
    {
        $p = $this->s()->config->get('BACKUP_PASSPHRASE', '');
        return $p === '' ? null : $p;
    }

    /** @return list<array{name:string,bytes:int,mtime:int,encrypted:bool}> */
    private function backupList(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/sicherung-*.zip*') ?: [] as $f) {
            if (is_file($f) && preg_match('/^sicherung-\d{8}-\d{6}\.zip(\.enc)?$/', basename($f)) === 1) {
                $out[] = ['id' => (string) preg_replace('/\\.zip(\\.enc)?$/', '', basename($f)), 'name' => basename($f), 'bytes' => (int) filesize($f), 'mtime' => (int) filemtime($f), 'encrypted' => str_ends_with($f, '.enc')];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Kennung = Dateiname ohne Endung (Punkte sind in Routen nicht erlaubt). */
    private function backupFile(string $id): string
    {
        $this->requireSys();
        if (preg_match('/^sicherung-\d{8}-\d{6}$/', $id) === 1) {
            foreach (['.zip.enc', '.zip'] as $ext) {
                if (is_file($this->backupDir() . '/' . $id . $ext)) {
                    return $this->backupDir() . '/' . $id . $ext;
                }
            }
        }
        throw new \App\Core\NotFoundException();
    }

    private function requireSys(): void
    {
        if (!$this->p()->isSystemAdmin || $this->p()->tenantId !== null) {
            throw new ForbiddenException();
        }
    }

    /** @param array<string,mixed> $params */
    public function backups(Request $r, array $params = []): Response
    {
        $this->requireSys();
        return $this->render('system/backups', [
            'files' => $this->backupList(),
            'passSet' => $this->backupPass() !== null,
            'keep' => max(1, $this->s()->config->int('BACKUP_KEEP', 14)),
            'result' => $this->x->session->get('backup_result'),
            'free' => @disk_free_space($this->s()->storageRoot),
        ]);
    }

    /** @param array<string,mixed> $params */
    public function backupCreate(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system/sicherungen', 'Sicherung erstellt.', function () use ($r): void {
            $this->requireSys();
            @set_time_limit(600);
            $svc = new BackupService($this->s()->db, $this->s()->storageRoot);
            try {
                $res = $svc->create($this->backupDir(), $this->backupPass());
            } catch (\Throwable $e) {
                $this->s()->audit->log('system.backup_created', 'failure', null, $this->p()->userId, null, null, ['error' => mb_substr($e->getMessage(), 0, 200)]);
                throw new BusinessRuleException('Sicherung fehlgeschlagen: ' . mb_substr($e->getMessage(), 0, 200));
            }
            $pruned = $svc->prune($this->backupDir(), max(1, $this->s()->config->int('BACKUP_KEEP', 14)));
            $v = $svc->verify($res['path'], $this->backupPass());
            $this->s()->audit->log('system.backup_created', 'success', null, $this->p()->userId, null, null, ['file' => basename($res['path']), 'bytes' => $res['bytes'], 'encrypted' => $res['encrypted'], 'verified' => $v['ok']]);
            $this->x->session->set('backup_result', ['name' => basename($res['path']), 'ok' => $v['ok'], 'entries' => $v['entries'] ?? 0, 'errors' => $v['errors'] ?? [], 'pruned' => $pruned, 'at' => gmdate('c')]);
        });
    }

    /** @param array<string,mixed> $params */
    public function backupVerify(Request $r, array $params): Response
    {
        $name = (string) ($params['name'] ?? '');
        return $this->act($r, '/system/sicherungen', null, function () use ($name): void {
            $file = $this->backupFile($name);
            $name = basename($file);
            @set_time_limit(600);
            $v = (new BackupService($this->s()->db, $this->s()->storageRoot))->verify($file, $this->backupPass());
            $this->s()->audit->log('system.backup_verified', $v['ok'] ? 'success' : 'failure', null, $this->p()->userId, null, null, ['file' => $name]);
            $this->x->session->set('backup_result', ['name' => $name, 'ok' => $v['ok'], 'entries' => $v['entries'] ?? 0, 'errors' => $v['errors'] ?? [], 'pruned' => 0, 'at' => gmdate('c')]);
        });
    }

    /** @param array<string,mixed> $params */
    public function backupDownload(Request $r, array $params): Response
    {
        $name = (string) ($params['name'] ?? '');
        $file = $this->backupFile($name);
        $name = basename($file);
        $this->s()->audit->log('system.backup_downloaded', 'success', null, $this->p()->userId, null, null, ['file' => $name]);
        return Response::download($file, $name, 'application/octet-stream');
    }

    /** @param array<string,mixed> $params */
    public function backupDelete(Request $r, array $params): Response
    {
        $name = (string) ($params['name'] ?? '');
        return $this->act($r, '/system/sicherungen', 'Sicherung gelöscht.', function () use ($name): void {
            $file = $this->backupFile($name);
            $name = basename($file);
            @unlink($file);
            $this->s()->audit->log('system.backup_deleted', 'success', null, $this->p()->userId, null, null, ['file' => $name]);
        });
    }

    // ------------------------------------------------------------ Cron

    /** @param array<string,mixed> $params */
    public function cronRun(Request $r, array $params = []): Response
    {
        return $this->act($r, '/system', null, function (): void {
            $this->requireSys();
            @set_time_limit(300);
            $res = (new CronTasks($this->s()))->run();
            $this->s()->audit->log('system.cron_manual', $res['failed'] === [] ? 'success' : 'failure', null, $this->p()->userId, null, null, ['status' => $res['status']]);
            if ($res['failed'] !== []) {
                throw new BusinessRuleException('Aufgaben mit Fehlern: ' . implode('; ', array_map(static fn (string $f): string => mb_substr($f, 0, 120), $res['failed'])));
            }
            $this->x->session->flash('success', $res['status'] === 'skipped' ? 'Die Aufgaben laufen bereits (Cron).' : 'Aufgaben ausgeführt: ' . $res['message']);
        });
    }
}
