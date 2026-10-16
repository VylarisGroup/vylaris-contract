<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\DomainException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use Tests\Support\TestApp;
use Tests\TestCase;

final class AdminTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $admin;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->admin = $this->app->actor($this->tenant, 'tenant_admin');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    public function testInviteCreatesPendingUserMembershipAndMail(): void
    {
        $dep = $this->app->tenantAdmin->saveDepartment($this->admin, 'Einkauf');
        $uid = $this->app->tenantAdmin->invite($this->admin, 'Neu@Example.com', 'Neue Person', 'contract_manager', [$dep]);
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$uid]);
        $this->assertSame('neu@example.com', $u['email']);
        $this->assertSame('pending', $u['status']);
        $this->assertNull($u['password_hash']);
        $this->assertSame(1, count($this->app->mailer->sent));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM department_memberships WHERE user_id = ? AND department_id = ?', [$uid, $dep]));
        $this->assertSame(null, $this->app->ac->principal($uid, $this->tenant), 'pending kann nicht arbeiten');
    }

    public function testInviteValidationAndRoleWhitelist(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->invite($this->admin, 'kaputt', 'X', 'reader'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->invite($this->admin, 'a@example.com', 'X', 'system_admin'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->invite($this->admin, 'a@example.com', 'X', 'auditor'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->invite($this->admin, 'a@example.com', 'X', 'reader', [999999]));
        $ext = $this->app->externalUser($this->tenant, 'auditor');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->tenantAdmin->invite($this->admin, $ext['email'], 'X', 'reader'));
    }

    public function testOnlyAdminsManageUsersAndOnlyOwnTenant(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->tenantAdmin->invite($mgr, 'a@example.com', 'X', 'reader'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->tenantAdmin->members($mgr));
        $other = $this->app->actor($this->app->fx->tenant(), 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tenantAdmin->setRole($other, $mgr->userId, 'reader'));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tenantAdmin->setMembershipStatus($other, $mgr->userId, 'revoked'));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tenantAdmin->resetMfa($other, $mgr->userId));
        $this->assertSame(1, count($this->app->tenantAdmin->members($other)), 'nur eigene Mitglieder');
    }

    public function testRoleChangeKillsSessionsAndLastAdminIsProtected(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $epoch = $this->app->auth->sessionEpoch($mgr->userId);
        $this->app->tenantAdmin->setRole($this->admin, $mgr->userId, 'reader');
        $this->assertTrue($this->app->auth->sessionEpoch($mgr->userId) > $epoch);
        $this->assertTrue($this->app->ac->principal($mgr->userId, $this->tenant)->hasRole('reader'));
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->tenantAdmin->setRole($this->admin, $this->admin->userId, 'reader'), 'letzte Admin-Rolle');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->tenantAdmin->setMembershipStatus($this->admin, $this->admin->userId, 'suspended'));
        $second = $this->app->actor($this->tenant, 'tenant_admin');
        $this->app->tenantAdmin->setRole($this->admin, $this->admin->userId, 'reader');
        $this->assertTrue($second->userId > 0);
    }

    public function testSuspendedMemberLosesAccessImmediately(): void
    {
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->app->tenantAdmin->setMembershipStatus($this->admin, $mgr->userId, 'suspended');
        $this->assertNull($this->app->ac->principal($mgr->userId, $this->tenant));
        $this->app->tenantAdmin->setMembershipStatus($this->admin, $mgr->userId, 'active');
        $this->assertTrue($this->app->ac->principal($mgr->userId, $this->tenant) !== null);
    }

    public function testMfaResetByAdminIsAudited(): void
    {
        $u = $this->app->loginUser();
        $this->app->fx->member($this->tenant, $u['id'], 'reader');
        $s = $this->app->auth->beginMfaEnrollment($u['id']);
        $this->app->auth->confirmMfaEnrollment($u['id'], \App\Security\Totp::code($s['secret'], \App\Security\Totp::counterAt($this->app->clock->now()->getTimestamp())));
        $this->app->tenantAdmin->resetMfa($this->admin, $u['id']);
        $this->assertNull($this->db->value('SELECT mfa_enabled_at FROM users WHERE id = ?', [$u['id']]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'auth.mfa_reset_by_admin' AND object_id = ?", [$u['id']]));
    }

    public function testDepartmentsAndSettingsValidation(): void
    {
        $d = $this->app->tenantAdmin->saveDepartment($this->admin, 'Vertrieb');
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->saveDepartment($this->admin, 'Vertrieb'));
        $this->app->tenantAdmin->saveDepartment($this->admin, 'Sales', $d);
        $this->assertSame('Sales', $this->app->tenantAdmin->departments($this->admin)[0]['name']);
        $other = $this->app->actor($this->app->fx->tenant(), 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tenantAdmin->saveDepartment($other, 'Hack', $d));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->tenantAdmin->setDepartmentMember($other, $d, $this->admin->userId, true));
        foreach ([['name' => ''], ['timezone' => 'Mars/Base'], ['jurisdiction_default' => 'Schweiz'], ['contract_number_prefix' => 'V-X!']] as $bad) {
            $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->saveSettings($this->admin, $bad + ['name' => 'X', 'timezone' => 'Europe/Zurich', 'jurisdiction_default' => 'CH', 'contract_number_prefix' => 'V']));
        }
        $this->app->tenantAdmin->saveSettings($this->admin, ['name' => 'Neu AG', 'timezone' => 'Europe/Zurich', 'jurisdiction_default' => 'ch', 'contract_number_prefix' => 'NA', 'mfa_required' => '1']);
        $this->assertSame(1, (int) $this->app->tenantAdmin->settings($this->admin)['mfa_required']);
    }

    public function testCustomTypesAndFields(): void
    {
        $this->app->tenantAdmin->saveContractType($this->admin, 'rahmen', 'Rahmenvertrag');
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->saveContractType($this->admin, 'service', 'Doppelt'));
        $this->app->tenantAdmin->saveCustomField($this->admin, 'kostenstelle', 'Kostenstelle', 'text', true);
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->saveCustomField($this->admin, 'kostenstelle', 'Nochmal', 'text', false));
        $this->assertThrows(ValidationException::class, fn () => $this->app->tenantAdmin->saveCustomField($this->admin, 'wahl', 'Wahl', 'select', false, []));
    }

    // ---- Systemadministration

    private function sysadmin(): \App\Models\Principal
    {
        $u = $this->app->loginUser();
        $this->db->run('UPDATE users SET is_system_admin = 1 WHERE id = ?', [$u['id']]);
        return $this->app->ac->principal($u['id'], null);
    }

    public function testSystemAdminCreatesTenantAndInvitesFirstAdmin(): void
    {
        $sys = $this->sysadmin();
        $tid = $this->app->sysAdmin->createTenant($sys, 'Neue Firma AG', 'neue-firma', 'chef@neuefirma.ch', 'Chef');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM tenant_memberships tm JOIN roles r ON r.id = tm.role_id WHERE tm.tenant_id = ? AND r.code = 'tenant_admin'", [$tid]));
        $this->assertSame(1, count($this->app->mailer->sent));
        $this->assertThrows(ValidationException::class, fn () => $this->app->sysAdmin->createTenant($sys, 'Doppelt', 'neue-firma', 'x@y.ch', 'X'));
        $this->assertThrows(ValidationException::class, fn () => $this->app->sysAdmin->createTenant($sys, 'Bad', 'Bad Slug!', 'x@y.ch', 'X'));
    }

    public function testTenantAdminIsNotSystemAdmin(): void
    {
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->sysAdmin->createTenant($this->admin, 'X', 'xx', 'a@b.ch', 'A'));
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->sysAdmin->tenants($this->admin));
    }

    public function testSystemAdminCannotReadContractsAndSuspensionBlocksTenant(): void
    {
        $sys = $this->sysadmin();
        $mgr = $this->app->actor($this->tenant, 'contract_manager');
        $c = $this->app->fx->contract($this->tenant, $mgr->userId);
        $this->assertThrows(DomainException::class, fn () => $this->app->ac->contract($sys, $c, 'view'));
        $this->assertSame([], $this->app->search->search($sys, [])['rows']);
        $this->app->sysAdmin->setTenantStatus($sys, $this->tenant, 'suspended');
        $this->assertNull($this->app->ac->principal($mgr->userId, $this->tenant), 'gesperrter Mandant');
        $this->app->sysAdmin->setTenantStatus($sys, $this->tenant, 'active');
        $this->assertTrue($this->app->ac->principal($mgr->userId, $this->tenant) !== null);
    }
}
