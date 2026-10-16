<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Modules\Signatures\CurlTransport;
use App\Modules\Signatures\HttpTransport;
use App\Modules\Signatures\ProviderException;
use App\Modules\Signatures\SignatureService;
use App\Modules\Signatures\SignerSpec;
use App\Modules\Signatures\SkribbleProvider;
use App\Modules\Signatures\ProviderRequestSpec;
use Tests\Support\TestApp;
use Tests\TestCase;

final class SignatureTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function approvedContract(): int
    {
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->app->contracts->create($this->mgr, $this->app->contractInput());
        $this->app->contracts->saveBody($this->mgr, $id, '<p>Der Anbieter erbringt Leistungen.</p>');
        $vid = $this->app->approvals->request($this->mgr, $id, [[$appr->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->app->approvals->decide($appr, $ap, 'approve');
        return $id;
    }

    /** @return list<SignerSpec> */
    private function signers(): array
    {
        return [new SignerSpec('a@example.com', 'Anna Muster', 1), new SignerSpec('b@example.com', 'Ben Beispiel', 2)];
    }

    private function status(int $contractId): string
    {
        return (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$contractId]);
    }

    public function testRequestBindsToApprovedVersionAndDocumentHash(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $r = $this->db->one('SELECT * FROM signature_requests WHERE id = ?', [$rid]);
        $this->assertSame('submitted', $r['status']);
        $this->assertSame('sandbox', $r['mode']);
        $doc = $this->db->one('SELECT * FROM documents WHERE id = ?', [(int) $r['document_id']]);
        $this->assertSame($doc['sha256'], $r['document_sha256']);
        $this->assertSame((int) $this->db->value('SELECT approved_version_id FROM contracts WHERE id = ?', [$id]), (int) $r['contract_version_id']);
        $this->assertSame('signature', $this->status($id));
    }

    public function testCannotRequestBeforeApproval(): void
    {
        $id = $this->app->contracts->create($this->mgr, $this->app->contractInput());
        $this->app->contracts->saveBody($this->mgr, $id, '<p>x</p>');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers()));
    }

    public function testReaderAndForeignTenantCannotRequest(): void
    {
        $id = $this->approvedContract();
        $reader = $this->app->actor($this->tenant, 'reader');
        $this->assertThrows(ForbiddenException::class, fn () => $this->app->signatures->request($reader, $id, 'SES', $this->signers()));
        $t2 = $this->app->fx->tenant();
        $other = $this->app->actor($t2, 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->signatures->request($other, $id, 'SES', $this->signers()));
    }

    public function testUnsupportedLevelAndBadSignersRejected(): void
    {
        $id = $this->approvedContract();
        $this->assertThrows(ValidationException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'QES', $this->signers()), 'Sandbox bietet kein QES');
        $this->assertThrows(ValidationException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', []));
        $this->assertThrows(ValidationException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', [new SignerSpec('kaputt', 'X')]));
        $this->assertThrows(ValidationException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'A'), new SignerSpec('A@example.com', 'B')]));
        $this->assertSame('approved', $this->status($id), 'Validierungsfehler ändern den Status nicht');
    }

    public function testFailedExternalCallProducesNoFalseSuccess(): void
    {
        $id = $this->approvedContract();
        $this->app->sandbox->failNextCreate = true;
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers()));
        $this->assertSame('approved', $this->status($id), 'Vertrag bleibt freigegeben');
        $r = $this->db->one('SELECT * FROM signature_requests WHERE contract_id = ?', [$id]);
        $this->assertSame('failed', $r['status']);
        $this->assertNull($r['external_transaction_id']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM api_transactions WHERE outcome = 'failure' AND related_id = ?", [(int) $r['id']]));
        // erneuter Versuch danach möglich
        $rid2 = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->assertSame('submitted', (string) $this->db->value('SELECT status FROM signature_requests WHERE id = ?', [$rid2]));
    }

    public function testNoSecondOpenRequest(): void
    {
        $id = $this->approvedContract();
        $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers()));
    }

    public function testSandboxCompletionNeverActivatesContractAndIsLabeled(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $ext = (string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]);
        $this->assertSame('submitted', $this->app->signatures->refresh($this->mgr, $rid), 'noch offen');
        $this->app->sandbox->simulate($ext, 'signed');
        $this->assertSame('signed', $this->app->signatures->refresh($this->mgr, $rid));
        $this->assertSame('signature', $this->status($id), 'Sandbox aktiviert nie');
        $ev = $this->db->one("SELECT * FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'signed_document'", [$rid]);
        $this->assertSame(1, (int) $ev['is_sandbox']);
        $this->assertSame('UNKNOWN', $ev['achieved_level']);
        $this->assertSame('not_validated', $ev['validation_status']);
        $this->assertSame('evidence', (string) $this->db->value('SELECT kind FROM documents WHERE id = ?', [(int) $ev['document_id']]));
        $d = $this->app->signatures->get($this->mgr, $rid)['display'];
        $this->assertFalse($d['legal_valid']);
        $this->assertContains('TESTMODUS', $d['notice']);
    }

    public function testRefreshIsIdempotent(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->app->sandbox->simulate((string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]), 'signed');
        $this->app->signatures->refresh($this->mgr, $rid);
        $this->app->signatures->refresh($this->mgr, $rid);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'signed_document'", [$rid]));
    }

    public function testDeclinedAndExpiredReturnContractToApproved(): void
    {
        foreach (['declined', 'expired'] as $state) {
            $id = $this->approvedContract();
            $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
            $this->app->sandbox->simulate((string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]), $state);
            $this->assertSame($state, $this->app->signatures->refresh($this->mgr, $rid));
            $this->assertSame('approved', $this->status($id));
        }
    }

    public function testUnknownProviderStatusIsNeverTreatedAsSuccess(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->app->sandbox->simulate((string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]), 'banane');
        $this->assertSame('submitted', $this->app->signatures->refresh($this->mgr, $rid));
        $this->assertSame('signature', $this->status($id));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM signature_events WHERE signature_request_id = ? AND event_type = 'unknown_status'", [$rid]));
    }

    public function testCancelWithdrawsAndRestoresApproved(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->assertThrows(ValidationException::class, fn () => $this->app->signatures->cancel($this->mgr, $rid, ' '));
        $this->app->signatures->cancel($this->mgr, $rid, 'Vertrag wird überarbeitet');
        $this->assertSame('cancelled', (string) $this->db->value('SELECT status FROM signature_requests WHERE id = ?', [$rid]));
        $this->assertSame('approved', $this->status($id));
    }

    public function testOtherTenantCannotReadOrRefreshRequest(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $other = $this->app->actor($this->app->fx->tenant(), 'contract_manager');
        $this->assertThrows(NotFoundException::class, fn () => $this->app->signatures->get($other, $rid));
        $this->assertThrows(NotFoundException::class, fn () => $this->app->signatures->refresh($other, $rid));
    }

    public function testEventsAndEvidenceAreImmutable(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->app->sandbox->simulate((string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]), 'signed');
        $this->app->signatures->refresh($this->mgr, $rid);
        $this->assertThrows(\Throwable::class, fn () => $this->db->run('UPDATE signature_events SET detail = ? WHERE signature_request_id = ?', ['x', $rid]));
        $this->assertThrows(\Throwable::class, fn () => $this->db->run('DELETE FROM signature_evidence WHERE signature_request_id = ?', [$rid]));
        $this->assertThrows(\Throwable::class, fn () => $this->db->run('UPDATE signature_requests SET document_sha256 = ? WHERE id = ?', [str_repeat('a', 64), $rid]));
    }

    public function testRefreshOpenForCronHandlesErrorsPerItem(): void
    {
        $id = $this->approvedContract();
        $rid = $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers());
        $this->app->sandbox->simulate((string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]), 'declined');
        $res = $this->app->signatures->refreshOpen();
        $this->assertSame(1, $res['checked']);
        $this->assertSame(1, $res['changed']);
        $this->assertSame('declined', (string) $this->db->value('SELECT status FROM signature_requests WHERE id = ?', [$rid]));
    }

    public function testTamperedDocumentBlocksRequest(): void
    {
        $id = $this->approvedContract();
        $vid = (int) $this->db->value('SELECT approved_version_id FROM contracts WHERE id = ?', [$id]);
        $this->app->documents->signableDocument($id, $vid, $this->mgr->userId);
        $doc = $this->db->one("SELECT * FROM documents WHERE contract_version_id = ? AND kind = 'generated'", [$vid]);
        file_put_contents($this->app->store->absolutePath($doc), 'manipuliert');
        $this->assertThrows(BusinessRuleException::class, fn () => $this->app->signatures->request($this->mgr, $id, 'SES', $this->signers()));
        $this->assertSame('approved', $this->status($id));
    }

    // ---- Skribble-Adapter gegen simulierten Transport (KEIN Live-Test)

    private function fakeHttp(array &$calls, array $script): HttpTransport
    {
        return new class ($calls, $script) implements HttpTransport {
            public function __construct(private array &$calls, private array $script)
            {
            }

            public function request(string $method, string $url, array $headers, ?string $body): array
            {
                $this->calls[] = [$method, $url, $headers, $body];
                $key = $method . ' ' . parse_url($url, PHP_URL_PATH);
                foreach ($this->script as $pattern => $resp) {
                    if (preg_match('#^' . $pattern . '$#', $key) === 1) {
                        if ($resp instanceof \Throwable) {
                            throw $resp;
                        }
                        return $resp;
                    }
                }
                return ['status' => 404, 'body' => ''];
            }
        };
    }

    public function testSkribbleMapsKnownStatusesAndNeverInventsSuccess(): void
    {
        $calls = [];
        $http = $this->fakeHttp($calls, [
            'POST /v2/access/login' => ['status' => 200, 'body' => 'abc.def.ghi'],
            'GET /v2/signature-requests/SR1' => ['status' => 200, 'body' => '{"status_overall":"SOMETHING_NEW"}'],
            'GET /v2/signature-requests/SR2' => ['status' => 200, 'body' => '{"status_overall":"SIGNED","document_id":"D9"}'],
        ]);
        $p = new SkribbleProvider($http, 'https://example.invalid/v2', 'u', 'k', 'sandbox');
        $this->assertSame('unknown', $p->fetchStatus('SR1')->state);
        $s = $p->fetchStatus('SR2');
        $this->assertSame('signed', $s->state);
        $this->assertSame('D9', $s->signedDocumentRef);
        $this->assertSame('not_validated', $p->validate('%PDF-')->status);
        $this->assertContains('Bearer abc.def.ghi', $calls[1][2]['Authorization']);
    }

    public function testSkribbleHttpErrorsBecomeProviderExceptionsAndRetryFlag(): void
    {
        $calls = [];
        $http = $this->fakeHttp($calls, [
            'POST /v2/access/login' => ['status' => 200, 'body' => 'tok'],
            'POST /v2/signature-requests' => ['status' => 503, 'body' => 'secret personal data'],
        ]);
        $p = new SkribbleProvider($http, 'https://example.invalid/v2', 'u', 'k');
        $spec = new ProviderRequestSpec('t', 'm', '%PDF-1.4', str_repeat('a', 64), $this->signers(), 'SES', 'idem', '1');
        $e = $this->assertThrows(ProviderException::class, fn () => $p->createRequest($spec));
        $this->assertTrue($e->retryable);
        $this->assertFalse(str_contains($e->getMessage(), 'secret'), 'Antworttext nicht in Fehlermeldung');
    }

    public function testSkribbleRejectsNonHttpsAndBadLogin(): void
    {
        $calls = [];
        $this->assertThrows(\InvalidArgumentException::class, fn () => new SkribbleProvider($this->fakeHttp($calls, []), 'http://x', 'u', 'k'));
        $http = $this->fakeHttp($calls, ['POST /v2/access/login' => ['status' => 200, 'body' => '<html>nope</html>']]);
        $p = new SkribbleProvider($http, 'https://example.invalid/v2', 'u', 'k');
        $this->assertThrows(ProviderException::class, fn () => $p->fetchStatus('X'));
        $t = new CurlTransport();
        $this->assertThrows(ProviderException::class, fn () => $t->request('GET', 'http://example.com', [], null));
    }

    public function testSignedWithoutPdfIsNotSuccessForLiveProvider(): void
    {
        $id = $this->approvedContract();
        $calls = [];
        $http = $this->fakeHttp($calls, [
            'POST /v2/access/login' => ['status' => 200, 'body' => 'tok'],
            'POST /v2/signature-requests' => ['status' => 200, 'body' => '{"id":"SRX"}'],
            'GET /v2/signature-requests/SRX' => ['status' => 200, 'body' => '{"status_overall":"SIGNED","document_id":"D1"}'],
            'GET /v2/documents/D1/content' => ['status' => 200, 'body' => 'kein pdf'],
        ]);
        $svc = new SignatureService($this->db, $this->app->ac, $this->app->audit, $this->app->workflow, $this->app->documents, $this->app->store,
            new SkribbleProvider($http, 'https://example.invalid/v2', 'u', 'k', 'live'));
        $rid = $svc->request($this->mgr, $id, 'QES', $this->signers());
        $this->assertThrows(BusinessRuleException::class, fn () => $svc->refresh($this->mgr, $rid));
        $this->assertSame('signature', $this->status($id), 'nicht aktiviert');
        $this->assertSame('submitted', (string) $this->db->value('SELECT status FROM signature_requests WHERE id = ?', [$rid]));
    }

    public function testLiveSignedWithPdfActivatesButNeverClaimsQes(): void
    {
        $id = $this->approvedContract();
        $pdf = (new \App\Modules\Documents\PlainPdfRenderer())->render('Signiert', [['type' => 'p', 'text' => 'x', 'n' => null]]);
        $calls = [];
        $http = $this->fakeHttp($calls, [
            'POST /v2/access/login' => ['status' => 200, 'body' => 'tok'],
            'POST /v2/signature-requests' => ['status' => 200, 'body' => '{"id":"SRY"}'],
            'GET /v2/signature-requests/SRY' => ['status' => 200, 'body' => '{"status_overall":"SIGNED","document_id":"D2"}'],
            'GET /v2/documents/D2/content' => ['status' => 200, 'body' => $pdf],
        ]);
        $svc = new SignatureService($this->db, $this->app->ac, $this->app->audit, $this->app->workflow, $this->app->documents, $this->app->store,
            new SkribbleProvider($http, 'https://example.invalid/v2', 'u', 'k', 'live'));
        $rid = $svc->request($this->mgr, $id, 'QES', $this->signers());
        $this->assertSame('signed', $svc->refresh($this->mgr, $rid));
        $this->assertSame('active', $this->status($id));
        $ev = $this->db->one("SELECT * FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'signed_document'", [$rid]);
        $this->assertSame('UNKNOWN', $ev['achieved_level'], 'QES wird nicht behauptet');
        $this->assertSame('not_validated', $ev['validation_status']);
        $this->assertSame('signed', (string) $this->db->value('SELECT kind FROM documents WHERE id = ?', [(int) $ev['document_id']]));
        $this->assertFalse(SignatureService::describe($this->db->one('SELECT * FROM signature_requests WHERE id = ?', [$rid]))['legal_valid']);
    }
}
