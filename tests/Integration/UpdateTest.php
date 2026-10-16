<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Maintenance\UpdateService;
use Tests\TestCase;

/** Aktualisierung mit Attrappen für GitHub (echter Abruf ist in der Testumgebung nicht erreichbar). */
final class UpdateTest extends TestCase
{
    private string $base;
    private string $root;
    private string $storage;

    public function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/vp-upd-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/app';
        $this->storage = $this->root . '/storage';
        mkdir($this->storage, 0750, true);
        foreach (['src/Web', 'public', 'templates', 'database/migrations'] as $d) {
            mkdir($this->root . '/' . $d, 0750, true);
        }
        file_put_contents($this->root . '/VERSION', "1.0.0\n");
        file_put_contents($this->root . '/src/autoload.php', '<?php // alt');
        file_put_contents($this->root . '/src/Web/App.php', '<?php // alt');
        file_put_contents($this->root . '/public/index.php', '<?php // alt');
        file_put_contents($this->root . '/.env', 'GEHEIM=1');
        file_put_contents($this->storage . '/daten.txt', 'bleibt');
    }

    public function tearDown(): void
    {
        \App\Modules\Maintenance\BackupService::rrmdir($this->base);
    }

    /** @param array<string,string> $files */
    private function zip(string $version, array $files = [], string $prefix = 'VylarisGroup-vylaris-contract-abc123/'): string
    {
        $z = new \ZipArchive();
        $f = $this->base . '/release-' . bin2hex(random_bytes(3)) . '.zip';
        $z->open($f, \ZipArchive::CREATE);
        $base = ['VERSION' => $version . "\n", 'src/autoload.php' => '<?php // neu', 'src/Web/App.php' => '<?php // neu', 'public/index.php' => '<?php // neu',
            'public/.htaccess' => '# neu', 'public/assets/app.css' => 'body{}', '.env' => 'UEBERSCHRIEBEN', 'storage/daten.txt' => 'UEBERSCHRIEBEN', 'database/migrations/999_x.sql' => 'SELECT 1;'];
        foreach ($files + $base as $n => $c) {
            $z->addFromString($prefix . $n, $c);
        }
        $z->close();
        return $f;
    }

    private function svc(string $zipFile, string $tag = 'v1.1.0', ?\Closure $migrate = null, ?\Closure $backup = null, ?\Closure $health = null, string $dl = 'https://codeload.github.com/VylarisGroup/vylaris-contract/zip/refs/tags/v1.1.0'): UpdateService
    {
        $http = function (string $url, ?string $dest, int $max) use ($zipFile, $tag, $dl): array {
            if (str_contains($url, 'raw.githubusercontent.com') || str_contains($url, '/commits/')) {
                return ['status' => 404, 'body' => ''];
            }
            if (str_contains($url, 'api.github.com')) {
                return ['status' => 200, 'body' => json_encode(['tag_name' => $tag, 'name' => 'Rel', 'body' => 'Notizen', 'html_url' => 'https://github.com/x', 'published_at' => '2026-10-01T00:00:00Z', 'zipball_url' => $dl, 'assets' => []])];
            }
            copy($zipFile, (string) $dest);
            return ['status' => 200, 'body' => ''];
        };
        return new UpdateService($this->db, new AuditLogger($this->db, 'test'), $this->root, $this->storage, $http,
            $backup ?? fn (): string => $this->storage . '/backups/test.bak', $migrate ?? fn (): array => ['applied' => ['999_x.sql']], $health ?? fn (string $t): array => ['state' => 'ok', 'detail' => 'test']);
    }

    private function sysAdmin(): Principal
    {
        return new Principal(1, 'internal', true, null, ['system_admin'], [], [], true, 'Sys');
    }

    public function testSuccessfulUpdateReplacesCodeKeepsEnvAndStorage(): void
    {
        $r = $this->svc($this->zip('1.1.0'))->install($this->sysAdmin(), '1.1.0');
        $this->assertSame('1.1.0', trim((string) file_get_contents($this->root . '/VERSION')));
        $this->assertSame('<?php // neu', (string) file_get_contents($this->root . '/src/autoload.php'));
        $this->assertSame('GEHEIM=1', (string) file_get_contents($this->root . '/.env'));
        $this->assertSame('bleibt', (string) file_get_contents($this->storage . '/daten.txt'));
        $this->assertFalse(is_file($this->storage . '/maintenance.flag'), 'Wartungsmodus beendet');
        $this->assertSame(['999_x.sql'], $r['migrations']);
        $this->assertSame(64, strlen($r['sha256']));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'system.updated'"));
    }

    public function testMigrationFailureRestoresOldCodeAndClearsMaintenance(): void
    {
        $svc = $this->svc($this->zip('1.1.0'), 'v1.1.0', function (): array {
            throw new \RuntimeException('Migration kaputt');
        });
        $this->assertThrows(BusinessRuleException::class, fn () => $svc->install($this->sysAdmin(), '1.1.0'));
        $this->assertSame('1.0.0', trim((string) file_get_contents($this->root . '/VERSION')));
        $this->assertSame('<?php // alt', (string) file_get_contents($this->root . '/src/autoload.php'));
        $this->assertFalse(is_file($this->storage . '/maintenance.flag'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'system.update_failed'"));
        $this->assertSame(false, $svc->lastRun()['ok']);
    }

    public function testOnlySystemAdminMayCheckOrInstall(): void
    {
        $svc = $this->svc($this->zip('1.1.0'));
        $tenantAdmin = new Principal(2, 'internal', false, 5, ['tenant_admin'], ['tenant.manage'], [], true, 'T');
        $this->assertThrows(ForbiddenException::class, fn () => $svc->check($tenantAdmin));
        $this->assertThrows(ForbiddenException::class, fn () => $svc->install($tenantAdmin, '1.1.0'));
        $this->assertSame('1.0.0', $svc->currentVersion());
    }

    public function testZipSlipAndMismatchedVersionAreRejectedWithoutChanges(): void
    {
        $evil = $this->svc($this->zip('1.1.0', ['src/../../evil.php' => '<?php']));
        $this->assertThrows(BusinessRuleException::class, fn () => $evil->install($this->sysAdmin(), '1.1.0'));
        $this->assertFalse(is_file($this->base . '/evil.php'));
        $mismatch = $this->svc($this->zip('9.9.9'));
        $this->assertThrows(BusinessRuleException::class, fn () => $mismatch->install($this->sysAdmin(), '1.1.0'));
        $syntax = $this->svc($this->zip('1.1.0', ['src/Web/App.php' => '<?php function (']));
        $this->assertThrows(BusinessRuleException::class, fn () => $syntax->install($this->sysAdmin(), '1.1.0'));
        $this->assertSame('<?php // alt', (string) file_get_contents($this->root . '/src/autoload.php'));
        $this->assertFalse(is_file($this->storage . '/maintenance.flag'));
    }

    public function testNotNewerStaleExpectationAndForeignHostAreRefused(): void
    {
        $same = $this->svc($this->zip('1.0.0'), 'v1.0.0');
        $this->assertThrows(BusinessRuleException::class, fn () => $same->install($this->sysAdmin(), '1.0.0'));
        $stale = $this->svc($this->zip('1.1.0'));
        $this->assertThrows(BusinessRuleException::class, fn () => $stale->install($this->sysAdmin(), '1.0.5'));
        $foreign = $this->svc($this->zip('1.1.0'), 'v1.1.0', null, null, null, 'https://evil.example/x.zip');
        $this->assertThrows(BusinessRuleException::class, fn () => $foreign->check($this->sysAdmin()));
        $badTag = $this->svc($this->zip('1.1.0'), 'latest');
        $this->assertThrows(BusinessRuleException::class, fn () => $badTag->check($this->sysAdmin()));
        $this->assertTrue($stale->hostAllowed('https://api.github.com/x'));
        $this->assertFalse($stale->hostAllowed('http://api.github.com/x'));
        $this->assertFalse($stale->hostAllowed('https://github.com.evil.example/x'));
        $this->assertFalse($stale->hostAllowed('https://user@github.com/x'));
    }

    public function testPermissionsForcedAndDirectoryIdentityKept(): void
    {
        chmod($this->root . '/public', 0700);
        $inode = fileinode($this->root . '/public');
        umask(0077);
        $this->svc($this->zip('1.1.0', ['public/assets/neu/x.js' => 'x']))->install($this->sysAdmin(), '1.1.0');
        umask(0022);
        $this->assertSame($inode, fileinode($this->root . '/public'), 'public/ wird nicht ersetzt');
        foreach (['public', 'public/assets', 'public/assets/neu', 'src', 'src/Web'] as $d) {
            $this->assertSame('755', substr(sprintf('%o', fileperms($this->root . '/' . $d)), -3), $d);
        }
        foreach (['public/index.php', 'public/.htaccess', 'public/assets/neu/x.js', 'src/autoload.php'] as $f) {
            $this->assertSame('644', substr(sprintf('%o', fileperms($this->root . '/' . $f)), -3), $f);
        }
        $this->assertSame([], array_filter($this->svc($this->zip('1.1.0'))->diagnose(), fn ($c) => $c['label'] === 'Dateirechte (Webserver-Lesezugriff)' && $c['status'] !== 'ok'));
    }

    public function testFailedHealthCheckRollsBackEverything(): void
    {
        file_put_contents($this->root . '/src/entfernt.php', '<?php // alt, nicht mehr im Paket');
        $svc0 = $this->svc($this->zip('1.1.0'));
        $svc0->install($this->sysAdmin(), '1.1.0');
        // zweites Update: Gesundheitsprüfung schlägt fehl
        file_put_contents($this->root . '/VERSION', "1.1.0\n");
        $svc = $this->svc($this->zip('1.2.0', ['src/autoload.php' => '<?php // v12']), 'v1.2.0', null, null, fn (string $t): array => ['state' => 'failed', 'detail' => 'HTTP 403']);
        try {
            $svc->install($this->sysAdmin(), '1.2.0');
            $this->assertTrue(false, 'muss scheitern');
        } catch (BusinessRuleException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'HTTP 403'));
        }
        $this->assertSame('1.1.0', trim((string) file_get_contents($this->root . '/VERSION')));
        $this->assertSame('<?php // neu', (string) file_get_contents($this->root . '/src/autoload.php'));
        $this->assertFalse(is_file($this->storage . '/maintenance.flag'));
        $this->assertSame(false, $svc->lastRun()['ok']);
    }

    public function testStaleFilesRemovedOnlyIfPreviouslyInstalledAndShaPin(): void
    {
        $this->svc($this->zip('1.1.0', ['src/alt.php' => '<?php // a']))->install($this->sysAdmin(), '1.1.0');
        file_put_contents($this->root . '/src/eigene.php', '<?php // vom Betreiber');
        $this->svc($this->zip('1.2.0'), 'v1.2.0')->install($this->sysAdmin(), '1.2.0');
        $this->assertFalse(is_file($this->root . '/src/alt.php'), 'verwaiste Paketdatei entfernt');
        $this->assertTrue(is_file($this->root . '/src/eigene.php'), 'fremde Datei bleibt');
        $bad = $this->svc($this->zip('1.3.0'), 'v1.3.0');
        $this->assertThrows(BusinessRuleException::class, fn () => $bad->install($this->sysAdmin(), '1.3.0', str_repeat('a', 64)));
        $this->assertThrows(BusinessRuleException::class, fn () => $bad->install($this->sysAdmin(), '1.3.0', 'xyz'));
        $this->assertSame('1.2.0', trim((string) file_get_contents($this->root . '/VERSION')));
    }

    public function testRepairPermissionsAndManualRollback(): void
    {
        $svc = $this->svc($this->zip('1.1.0'));
        $svc->install($this->sysAdmin(), '1.1.0');
        chmod($this->root . '/public', 0700);
        chmod($this->root . '/src/autoload.php', 0600);
        $this->assertTrue($svc->repairPermissions($this->sysAdmin()) >= 2);
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->root . '/public')), -3));
        $svc->rollbackLast();
        $this->assertSame('<?php // alt', (string) file_get_contents($this->root . '/src/autoload.php'));
    }

    public function testMaintenanceFlagCarriesToken(): void
    {
        $svc = $this->svc($this->zip('1.1.0'));
        $svc->setMaintenance(true, 'tok');
        $this->assertSame('tok', json_decode((string) file_get_contents($this->storage . '/maintenance.flag'), true)['token']);
        $svc->setMaintenance(false);
        $this->assertFalse(is_file($this->storage . '/maintenance.flag'));
    }
}
