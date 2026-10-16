<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\FixedClock;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Modules\Audit\AuditLogger;
use App\Services\AccessControl;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Fixtures;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    private Fixtures $fx;
    private AccessControl $ac;
    private FixedClock $clock;

    public function setUp(): void
    {
        $this->fx = new Fixtures($this->db);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));
        $this->ac = new AccessControl($this->db, $this->clock, new AuditLogger($this->db, 'test'));
    }

    private function internal(int $tenant, string $role, ?int $dept = null): \App\Models\Principal
    {
        $u = $this->fx->user();
        $this->fx->member($tenant, $u, $role);
        if ($dept !== null) {
            $this->fx->departmentMember($tenant, $dept, $u);
        }
        $p = $this->ac->principal($u, $tenant);
        $this->assertTrue($p !== null, 'Principal konnte nicht aufgebaut werden');
        return $p;
    }

    private function external(int $tenant, string $type, string $role): \App\Models\Principal
    {
        $u = $this->fx->user($type);
        $this->fx->member($tenant, $u, $role);
        $p = $this->ac->principal($u, null);
        $this->assertTrue($p !== null);
        return $p;
    }

    // ---- 1. Fremder Vertrag über manipulierte ID

    public function testUserCannotReadContractOfForeignTenantViaManipulatedId(): void
    {
        $t1 = $this->fx->tenant('Mandant 1');
        $t2 = $this->fx->tenant('Mandant 2');
        $owner2 = $this->fx->user();
        $this->fx->member($t2, $owner2, 'tenant_admin');
        $foreign = $this->fx->contract($t2, $owner2);

        $admin1 = $this->internal($t1, 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($admin1, $foreign, 'view'));
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($admin1, $foreign, 'edit'));
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($admin1, $foreign, 'archive'));
    }

    public function testForeignAndNonexistentIdsAreIndistinguishable(): void
    {
        $t1 = $this->fx->tenant();
        $t2 = $this->fx->tenant();
        $o2 = $this->fx->user();
        $foreign = $this->fx->contract($t2, $o2);
        $admin1 = $this->internal($t1, 'tenant_admin');

        $e1 = $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($admin1, $foreign));
        $e2 = $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($admin1, 999999999));
        $this->assertSame($e1->getMessage(), $e2->getMessage());
        $this->assertSame($e1->httpStatus(), $e2->httpStatus());
    }

    public function testDeniedAccessIsAudited(): void
    {
        $t1 = $this->fx->tenant();
        $t2 = $this->fx->tenant();
        $o2 = $this->fx->user();
        $foreign = $this->fx->contract($t2, $o2);
        $admin1 = $this->internal($t1, 'tenant_admin');
        $before = (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'access.denied'");
        try {
            $this->ac->contract($admin1, $foreign);
        } catch (NotFoundException) {
        }
        $after = (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'access.denied'");
        $this->assertSame($before + 1, $after);
    }

    public function testMembershipInOtherTenantDoesNotGrantPrincipal(): void
    {
        $t1 = $this->fx->tenant();
        $t2 = $this->fx->tenant();
        $u = $this->fx->user();
        $this->fx->member($t1, $u, 'tenant_admin');
        $this->assertNull($this->ac->principal($u, $t2), 'Kein Principal für Mandant ohne Mitgliedschaft');
    }

    public function testSuspendedTenantAndDisabledUserYieldNoPrincipal(): void
    {
        $t = $this->fx->tenant();
        $u = $this->fx->user();
        $this->fx->member($t, $u, 'tenant_admin');
        $this->db->run("UPDATE tenants SET status = 'suspended' WHERE id = ?", [$t]);
        $this->assertNull($this->ac->principal($u, $t));

        $t2 = $this->fx->tenant();
        $d = $this->fx->user('internal', 'disabled');
        $this->fx->member($t2, $d, 'tenant_admin');
        $this->assertNull($this->ac->principal($d, $t2));
    }

    // ---- Rollen, Abteilungen, ACL

    public function testSystemAdminWithoutMembershipCannotReadContracts(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $sa = $this->fx->user();
        $this->db->run('UPDATE users SET is_system_admin = 1 WHERE id = ?', [$sa]);
        $p = $this->ac->principal($sa, null);
        $this->assertTrue($p !== null && $p->can('system.manage'));
        $this->assertFalse($p->can('contract.view'), 'Systemadmin hat kein Vertragsleserecht');
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($p, $cid));
    }

    public function testReaderNeedsExplicitAssignment(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $reader = $this->internal($t, 'reader');

        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($reader, $cid, 'view'));

        $this->db->run('INSERT INTO contract_acl (tenant_id, contract_id, user_id, can_edit, granted_by) VALUES (?,?,?,0,?)', [$t, $cid, $reader->userId, $owner]);
        $this->assertSame($cid, (int) $this->ac->contract($reader, $cid, 'view')['id']);
        // Leser dürfen nie bearbeiten, auch nicht mit ACL
        $this->assertThrows(ForbiddenException::class, fn () => $this->ac->contract($reader, $cid, 'edit'));
    }

    public function testDepartmentScoping(): void
    {
        $t = $this->fx->tenant();
        $d1 = $this->fx->department($t, 'Einkauf');
        $d2 = $this->fx->department($t, 'Vertrieb');
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner, $d1);

        $inDept = $this->internal($t, 'contract_manager', $d1);
        $otherDept = $this->internal($t, 'contract_manager', $d2);
        $admin = $this->internal($t, 'tenant_admin');

        $this->assertSame($cid, (int) $this->ac->contract($inDept, $cid)['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($otherDept, $cid));
        $this->assertSame($cid, (int) $this->ac->contract($admin, $cid)['id']);
    }

    public function testApproverSeesOnlyAssignedOrTenantWideContracts(): void
    {
        $t = $this->fx->tenant();
        $d = $this->fx->department($t);
        $owner = $this->fx->user();
        $deptContract = $this->fx->contract($t, $owner, $d);
        $approver = $this->internal($t, 'approver');
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($approver, $deptContract));

        $vid = (int) $this->db->value('SELECT current_version_id FROM contracts WHERE id = ?', [$deptContract]);
        $this->db->run('INSERT INTO approvals (tenant_id, contract_id, contract_version_id, approver_user_id, version_hash) VALUES (?,?,?,?,?)', [$t, $deptContract, $vid, $approver->userId, str_repeat('a', 64)]);
        $this->assertSame($deptContract, (int) $this->ac->contract($approver, $deptContract, 'view')['id']);
    }

    public function testApproverCannotEdit(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $approver = $this->internal($t, 'approver');
        $this->assertThrows(ForbiddenException::class, fn () => $this->ac->contract($approver, $cid, 'edit'));
    }

    // ---- 2. Externe Vertragspartner

    public function testCounterpartySeesOnlyAssignedApprovedContracts(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $assigned = $this->fx->contract($t, $owner);
        $other = $this->fx->contract($t, $owner);
        $this->fx->approve($assigned);
        $this->fx->approve($other);
        $ext = $this->external($t, 'external_party', 'external_party');
        $this->fx->mandate($t, $ext->userId, 'counterparty', [$assigned], ['read', 'download', 'sign'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);

        $this->assertSame($assigned, (int) $this->ac->contract($ext, $assigned, 'view')['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($ext, $other, 'view'));
    }

    public function testCounterpartyCannotSeeDraftContract(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $draft = $this->fx->contract($t, $owner);
        $ext = $this->external($t, 'external_party', 'external_party');
        $this->fx->mandate($t, $ext->userId, 'counterparty', [$draft], ['read'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($ext, $draft, 'view'));
    }

    public function testCounterpartyCannotSeeInternalComments(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $this->fx->approve($cid);
        $ext = $this->external($t, 'external_party', 'external_party');
        $internal = $this->internal($t, 'contract_manager');

        $this->assertSame(['shared'], $this->ac->visibleCommentScopes($ext));
        $this->assertSame(['internal', 'shared'], $this->ac->visibleCommentScopes($internal));
        $this->assertSame(['shared'], $this->ac->visibleCommentScopes($this->internal($t, 'reader')));
    }

    public function testCounterpartyActionNotInMandateIsDenied(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $this->fx->approve($cid);
        $ext = $this->external($t, 'external_party', 'external_party');
        $this->fx->mandate($t, $ext->userId, 'counterparty', [$cid], ['read'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);

        $this->assertSame($cid, (int) $this->ac->contract($ext, $cid, 'view')['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($ext, $cid, 'download'));
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($ext, $cid, 'sign'));
        // Schreibende Aktionen existieren für Externe nicht
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($ext, $cid, 'edit'));
    }

    // ---- Mandate: abgelaufen / widerrufen / noch nicht gültig

    public function testExpiredRevokedAndFutureMandatesAreBlocked(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $this->fx->approve($cid);
        $a = $this->external($t, 'auditor', 'auditor');
        $mid = $this->fx->mandate($t, $a->userId, 'audit', [$cid], ['read_versions'], '2026-06-01 00:00:00', '2026-06-30 00:00:00', $owner);

        $this->assertSame($cid, (int) $this->ac->contract($a, $cid, 'view')['id'], 'innerhalb des Zeitfensters');

        $this->clock->set(new DateTimeImmutable('2026-06-30 00:00:01', new DateTimeZone('UTC')));
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($a, $cid, 'view'), 'abgelaufen');

        $this->clock->set(new DateTimeImmutable('2026-05-31 23:59:59', new DateTimeZone('UTC')));
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($a, $cid, 'view'), 'noch nicht gültig');

        $this->clock->set(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));
        $this->assertSame($cid, (int) $this->ac->contract($a, $cid, 'view')['id']);
        $this->db->run("UPDATE external_mandates SET status = 'revoked', revoked_at = ? WHERE id = ?", ['2026-06-15 11:00:00', $mid]);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($a, $cid, 'view'), 'widerrufen');
    }

    // ---- 3. Revision

    public function testAuditorCannotChangeAnything(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $this->fx->approve($cid);
        $a = $this->external($t, 'auditor', 'auditor');
        $this->fx->mandate($t, $a->userId, 'audit', [$cid], ['read_versions', 'read_history', 'read_evidence', 'download_attachments', 'annotate', 'export_report'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);

        foreach (['edit', 'archive', 'approve', 'request_signature', 'request_approval', 'notary'] as $action) {
            $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($a, $cid, $action), "Revision darf nicht: {$action}");
        }
        $this->assertFalse($a->can('contract.edit'));
        $this->assertFalse($a->can('approval.decide'));
        $this->assertFalse($a->can('mandate.manage'));
    }

    public function testAuditorOnlySeesApprovedLockedVersion(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $v1 = $this->fx->approve($cid);
        $v2 = $this->fx->version($t, $cid, $owner, 2, $v1, 'Neuer Entwurf');
        $a = $this->external($t, 'auditor', 'auditor');
        $this->fx->mandate($t, $a->userId, 'audit', [$cid], ['read_versions'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);

        $this->assertSame($v1, (int) $this->ac->version($a, $v1)['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->version($a, $v2), 'unfreigegebener Entwurf');
    }

    public function testAuditorCannotSeeUnapprovedContract(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner, null, 'review');
        $a = $this->external($t, 'auditor', 'auditor');
        $this->fx->mandate($t, $a->userId, 'audit', [$cid], ['read_versions'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->contract($a, $cid, 'view'));
    }

    // ---- 4. Notariat

    public function testNotaryCannotDownloadUnapprovedVersion(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $v1 = $this->fx->approve($cid);
        $v2 = $this->fx->version($t, $cid, $owner, 2, $v1, 'Nicht freigegeben');
        $docApproved = $this->fx->document($t, $cid, $v1);
        $docDraft = $this->fx->document($t, $cid, $v2);
        $n = $this->external($t, 'notary', 'notary');
        $this->fx->mandate($t, $n->userId, 'notary', [$cid], ['read'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);
        $this->db->run(
            "INSERT INTO notary_cases (tenant_id, contract_id, contract_version_id, document_id, notary_user_id, notary_office, requested_by, responsible_user_id, status)
             VALUES (?,?,?,?,?,?,?,?, 'provided')",
            [$t, $cid, $v1, $docApproved, $n->userId, 'Notariat Muster', $owner, $owner],
        );

        $this->assertSame($docApproved, (int) $this->ac->document($n, $docApproved)['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->document($n, $docDraft), 'nicht freigegebene Version');
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->version($n, $v2));
    }

    public function testNotaryNeedsProvidedCase(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $v1 = $this->fx->approve($cid);
        $doc = $this->fx->document($t, $cid, $v1);
        $n = $this->external($t, 'notary', 'notary');
        $this->fx->mandate($t, $n->userId, 'notary', [$cid], ['read'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);
        $this->db->run(
            "INSERT INTO notary_cases (tenant_id, contract_id, contract_version_id, document_id, notary_user_id, notary_office, requested_by, responsible_user_id, status)
             VALUES (?,?,?,?,?,?,?,?, 'prepared')",
            [$t, $cid, $v1, $doc, $n->userId, 'Notariat Muster', $owner, $owner],
        );
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->document($n, $doc), 'vorbereitet, aber noch nicht bereitgestellt');
    }

    public function testExternalCannotDownloadDocumentNotMarkedVisible(): void
    {
        $t = $this->fx->tenant();
        $owner = $this->fx->user();
        $cid = $this->fx->contract($t, $owner);
        $v = $this->fx->approve($cid);
        $hidden = $this->fx->document($t, $cid, $v, 'attachment', false);
        $visible = $this->fx->document($t, $cid, $v, 'generated', true);
        $ext = $this->external($t, 'external_party', 'external_party');
        $this->fx->mandate($t, $ext->userId, 'counterparty', [$cid], ['read', 'download'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', $owner);

        $this->assertSame($visible, (int) $this->ac->document($ext, $visible)['id']);
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->document($ext, $hidden));
    }

    public function testInternalUserCannotReadForeignTenantDocument(): void
    {
        $t1 = $this->fx->tenant();
        $t2 = $this->fx->tenant();
        $o2 = $this->fx->user();
        $c2 = $this->fx->contract($t2, $o2);
        $doc = $this->fx->document($t2, $c2, null, 'attachment', false);
        $admin1 = $this->internal($t1, 'tenant_admin');
        $this->assertThrows(NotFoundException::class, fn () => $this->ac->document($admin1, $doc));
    }
}
