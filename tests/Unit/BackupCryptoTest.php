<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Maintenance\BackupService;
use Tests\TestCase;
use ZipArchive;

final class BackupCryptoTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vp-bt-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    public function tearDown(): void
    {
        BackupService::rrmdir($this->dir);
    }

    private function archive(): string
    {
        $path = $this->dir . '/a.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('db.sql', "SELECT 1;\n-- @@\n");
        $zip->addFromString('files/ab/cd/abcd', 'Inhalt');
        $zip->addFromString('manifest.json', json_encode(['entries' => ['db.sql' => hash('sha256', "SELECT 1;\n-- @@\n"), 'files/ab/cd/abcd' => hash('sha256', 'Inhalt')]]));
        $zip->close();
        return $path;
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $zip = $this->archive();
        BackupService::encryptFile($zip, $zip . '.enc', 'geheim-123');
        $this->assertFalse(str_contains((string) file_get_contents($zip . '.enc'), 'Inhalt'), 'Klartext darf nicht erkennbar sein');
        BackupService::decryptFile($zip . '.enc', $this->dir . '/b.zip', 'geheim-123');
        $this->assertSame(hash_file('sha256', $zip), hash_file('sha256', $this->dir . '/b.zip'));
    }

    public function testWrongPassphraseAndTamperingAreDetected(): void
    {
        $zip = $this->archive();
        BackupService::encryptFile($zip, $zip . '.enc', 'geheim-123');
        $this->assertThrows(\RuntimeException::class, fn () => BackupService::decryptFile($zip . '.enc', $this->dir . '/c.zip', 'falsch'));
        $raw = (string) file_get_contents($zip . '.enc');
        $raw[strlen($raw) - 5] = $raw[strlen($raw) - 5] === 'x' ? 'y' : 'x';
        file_put_contents($zip . '.tampered.enc', $raw);
        $this->assertThrows(\RuntimeException::class, fn () => BackupService::decryptFile($zip . '.tampered.enc', $this->dir . '/d.zip', 'geheim-123'));
        // Abgeschnittene Datei (Endmarke fehlt)
        file_put_contents($zip . '.cut.enc', substr((string) file_get_contents($zip . '.enc'), 0, -40));
        $this->assertThrows(\RuntimeException::class, fn () => BackupService::decryptFile($zip . '.cut.enc', $this->dir . '/e.zip', 'geheim-123'));
    }

    public function testVerifyDetectsModifiedEntry(): void
    {
        $zip = $this->archive();
        $svc = new BackupService(null, $this->dir);
        $this->assertTrue($svc->verify($zip, null)['ok']);
        $z = new ZipArchive();
        $z->open($zip);
        $z->addFromString('files/ab/cd/abcd', 'Manipuliert');
        $z->close();
        $res = $svc->verify($zip, null);
        $this->assertFalse($res['ok']);
        $this->assertContains('Hash stimmt nicht', implode(' ', $res['errors']));
    }
}
