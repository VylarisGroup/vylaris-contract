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
            '.env' => 'UEBERSCHRIEBEN', 'storage/daten.txt' => 'UEBERSCHRIEBEN', 'database/migrations/999_x.sql' => 'SELECT 1;'];
        foreach ($files + $base as $n => $c) {
            $z->addFromString($prefix . $n, $c);
        }
        $z->close();
        return $f;
    }

    private function svc(string $zipFile, string $tag = 'v1.1.0', ?\Closure $migrate = null, ?\Closure $backup = null, string $dl = 'https://codeload.github.com/VylarisGroup/vylaris-contract/zip/refs/tags/v1.1.0'): UpdateService
    {
        $http = function (string $url, ?string $dest, int $max) use ($zipFile, $tag, $dl): array {
            if (str_contains($url, 'api.github.com')) {
                return ['status' => 200, 'body' => json_encode(['tag_name' => $tag, 'name' => 'Rel', 'body' => 'Notizen', 'html_url' => 'https://github.com/x', 'published_at' => '2026-10-01T00:00:00Z', 'zipball_url' => $dl, 'assets' => []])];
            }
            copy($zipFile, (string) $dest);
            return ['status' => 200, 'body' => ''];
        };
        return new UpdateService($this->db, new AuditLogger($this->db, 'test'), $this->root, $this->storage, $http,
            $backup ?? fn (): string => $this->storage . '/backups/test.bak', $migrate ?? fn (): array => ['applied' => ['999_x.sql']]);
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
        $foreign = $this->svc($this->zip('1.1.0'), 'v1.1.0', null, null, 'https://evil.example/x.zip');
        $this->assertThrows(BusinessRuleException::class, fn () => $foreign->check($this->sysAdmin()));
        $badTag = $this->svc($this->zip('1.1.0'), 'latest');
        $this->assertThrows(BusinessRuleException::class, fn () => $badTag->check($this->sysAdmin()));
        $this->assertTrue($stale->hostAllowed('https://api.github.com/x'));
        $this->assertFalse($stale->hostAllowed('http://api.github.com/x'));
        $this->assertFalse($stale->hostAllowed('https://github.com.evil.example/x'));
        $this->assertFalse($stale->hostAllowed('https://user@github.com/x'));
    }
}
