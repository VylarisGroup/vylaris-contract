<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Models\Principal;
use App\Modules\Audit\AuditLogger;
use App\Modules\Maintenance\SettingsService;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    private string $key;
    private SettingsService $svc;
    private Config $env;

    public function setUp(): void
    {
        $this->key = Crypto::generateKey();
        $this->svc = new SettingsService($this->db, new AuditLogger($this->db, 'test'), $this->key);
        $this->env = new Config(['APP_KEY' => $this->key, 'SMTP_HOST' => 'env.example.org', 'SMTP_PASS' => 'env-geheim']);
    }

    private function sys(): Principal
    {
        return new Principal(1, 'internal', true, null, ['system_admin'], [], [], true, 'S');
    }

    public function testSaveOverridesEnvEncryptsSecretsAndResets(): void
    {
        $this->svc->save($this->sys(), 'smtp', ['SMTP_HOST' => 'smtp.neu.ch', 'SMTP_PORT' => '587', 'SMTP_ENCRYPTION' => 'starttls', 'SMTP_PASS' => 'Geheim!123'], [], $this->env);
        $eff = SettingsService::overlay($this->env, $this->db);
        $this->assertSame('smtp.neu.ch', $eff->get('SMTP_HOST'));
        $this->assertSame('Geheim!123', $eff->get('SMTP_PASS'));
        $raw = (string) $this->db->value("SELECT setting_value FROM system_settings WHERE setting_key = 'cfg.SMTP_PASS'");
        $this->assertFalse(str_contains($raw, 'Geheim'), 'Passwort liegt nicht im Klartext in der DB');
        $this->assertSame(1, (int) $this->db->value("SELECT is_secret FROM system_settings WHERE setting_key = 'cfg.SMTP_PASS'"));
        $view = $this->svc->view($this->env);
        $this->assertSame('', $view['SMTP_PASS']['value'], 'Geheimnis wird nie ausgegeben');
        $this->assertSame('Oberfläche', $view['SMTP_HOST']['source']);
        // leeres Geheimnis = unverändert
        $this->svc->save($this->sys(), 'smtp', ['SMTP_PASS' => ''], [], $this->env);
        $this->assertSame('Geheim!123', SettingsService::overlay($this->env, $this->db)->get('SMTP_PASS'));
        // Zurücksetzen → .env gilt wieder
        $this->svc->save($this->sys(), 'smtp', [], ['SMTP_HOST' => '1', 'SMTP_PASS' => '1'], $this->env);
        $eff = SettingsService::overlay($this->env, $this->db);
        $this->assertSame('env.example.org', $eff->get('SMTP_HOST'));
        $this->assertSame('env-geheim', $eff->get('SMTP_PASS'));
        $log = (string) $this->db->value("SELECT metadata FROM audit_events WHERE action = 'system.settings_changed' ORDER BY seq DESC LIMIT 1");
        $this->assertFalse(str_contains($log, 'Geheim'), 'Audit enthält keine Werte');
    }

    public function testValidationAndAccess(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'network', ['APP_URL' => 'http://unsicher.ch'], [], $this->env));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'network', ['TRUSTED_PROXIES' => '1.2.3.4, kein-ip'], [], $this->env));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'smtp', ['SMTP_PORT' => '99999'], [], $this->env));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'smtp', ['SMTP_ENCRYPTION' => 'ssl3'], [], $this->env));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'signature', ['SIGNATURE_PROVIDER' => 'skribble'], [], $this->env), 'Skribble braucht Zugangsdaten');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'unbekannt', [], [], $this->env));
        $t = new Principal(2, 'internal', false, 5, ['tenant_admin'], [], [], true, 'T');
        $this->assertThrows(ForbiddenException::class, fn () => $this->svc->save($t, 'display', ['APP_NAME' => 'X'], [], $this->env));
        // Unbekannte Schlüssel in der Tabelle werden ignoriert, nicht übernommen
        $this->db->run("INSERT INTO system_settings (setting_key, setting_value) VALUES ('cfg.DB_PASS', 'boese')");
        $this->assertFalse(array_key_exists('DB_PASS', $this->svc->overrides()));
        $this->svc->save($this->sys(), 'signature', ['SIGNATURE_PROVIDER' => 'sandbox'], [], $this->env);
        $ok = $this->svc->save($this->sys(), 'skribble', ['SKRIBBLE_BASE_URL' => 'https://api.example.ch', 'SKRIBBLE_USER' => 'u', 'SKRIBBLE_KEY' => 'k', 'SKRIBBLE_MODE' => 'sandbox'], [], $this->env);
        $this->assertTrue(in_array('SKRIBBLE_KEY', $ok, true));
        $this->svc->save($this->sys(), 'signature', ['SIGNATURE_PROVIDER' => 'skribble'], [], $this->env);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->save($this->sys(), 'skribble', ['SKRIBBLE_USER' => ''], ['SKRIBBLE_KEY' => '1'], $this->env), 'Aktiver Skribble braucht Zugangsdaten');
    }
}
