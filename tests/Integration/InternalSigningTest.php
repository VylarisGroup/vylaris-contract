<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\BusinessRuleException;
use App\Core\NotFoundException;
use App\Core\ValidationException;
use App\Modules\Signatures\InternalSignatureProvider;
use App\Modules\Signatures\InternalSigningService;
use App\Modules\Signatures\SignatureService;
use App\Modules\Signatures\SignerSpec;
use Tests\Support\TestApp;
use Tests\TestCase;

final class InternalSigningTest extends TestCase
{
    private TestApp $app;
    private int $tenant;
    private \App\Models\Principal $mgr;
    private InternalSigningService $svc;
    private SignatureService $sig;

    public function setUp(): void
    {
        $this->app = new TestApp($this->db);
        $this->tenant = $this->app->fx->tenant();
        $this->mgr = $this->app->actor($this->tenant, 'contract_manager');
        $this->svc = new InternalSigningService($this->db, $this->app->clock, $this->app->audit, $this->app->mailer, $this->app->limiter, $this->app->store, str_repeat('k', 32), 'https://test.invalid', 'Test');
        $this->sig = new SignatureService($this->db, $this->app->ac, $this->app->audit, $this->app->workflow, $this->app->documents, $this->app->store, new InternalSignatureProvider($this->svc));
        $this->svc->onComplete(fn (int $rid) => $this->sig->syncRequest($rid));
    }

    public function tearDown(): void
    {
        $this->app->cleanup();
    }

    private function approvedContract(): int
    {
        $appr = $this->app->actor($this->tenant, 'approver');
        $id = $this->app->contracts->create($this->mgr, $this->app->contractInput());
        $this->app->contracts->saveBody($this->mgr, $id, '<p>Der Anbieter erbringt Leistungen für Müller &amp; Söhne.</p>');
        $vid = $this->app->approvals->request($this->mgr, $id, [[$appr->userId]]);
        $ap = (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]);
        $this->app->approvals->decide($appr, $ap, 'approve');
        return $id;
    }

    /** @return array<string,string> E-Mail => Token aus den Einladungen */
    private function tokens(): array
    {
        $t = [];
        foreach ($this->app->mailer->sent as $m) {
            if (preg_match('~/unterschreiben/([A-Za-z0-9_-]{43})~', $m['body'], $x)) {
                $t[$m['to']] = $x[1];
            }
        }
        return $t;
    }

    private function lastCode(string $to): string
    {
        for ($i = count($this->app->mailer->sent) - 1; $i >= 0; $i--) {
            $m = $this->app->mailer->sent[$i];
            if ($m['to'] === $to && preg_match('~\n\s+(\d{6})\n~', $m['body'], $x)) {
                return $x[1];
            }
        }
        $this->fail('kein Code gefunden');
    }

    private function signAs(string $email, string $token, string $name): void
    {
        $this->svc->sendCode($token);
        $this->svc->sign($token, $this->lastCode($email), $name, true, '203.0.113.5', 'TestBrowser');
    }

    private function status(int $id): string
    {
        return (string) $this->db->value('SELECT status FROM contracts WHERE id = ?', [$id]);
    }

    public function testParallelFlowSealsAndActivatesContract(): void
    {
        $id = $this->approvedContract();
        $rid = $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1), new SignerSpec('b@example.com', 'Ben Beispiel', 1)]);
        $tk = $this->tokens();
        $this->assertSame(2, count($tk), 'beide parallel eingeladen');
        $this->assertSame('signature', $this->status($id));

        $this->signAs('a@example.com', $tk['a@example.com'], 'Anna Muster');
        $this->assertSame('signature', $this->status($id), 'erst eine Unterschrift');
        $this->signAs('b@example.com', $tk['b@example.com'], 'Ben Beispiel');

        $this->assertSame('signed', $this->sig->refresh($this->mgr, $rid));
        $this->assertSame('active', $this->status($id), 'Live-Anbieter aktiviert nach Validierung');
        $ev = $this->db->one("SELECT * FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'signed_document'", [$rid]);
        $this->assertSame(0, (int) $ev['is_sandbox']);
        $this->assertSame('valid', $ev['validation_status']);
        $this->assertSame('SES', $ev['achieved_level']);

        $ext = (string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]);
        $doc = $this->svc->finalDocument($ext);
        $this->assertSame('%PDF', substr($doc->bytes, 0, 4));
        $this->assertSame('valid', $this->svc->validate($doc->bytes)->status);
        $tampered = $doc->bytes;
        $tampered[200] = $tampered[200] === 'A' ? 'B' : 'A';
        $this->assertTrue($this->svc->validate($tampered)->status !== 'valid', 'veränderte Bytes sind nie gültig');
        $this->assertTrue($this->svc->validate($doc->bytes . "\n%x")->status !== 'valid', 'angehängte Bytes sind nie gültig');
    }

    public function testSequentialOrderInvitesNextOnlyAfterPrevious(): void
    {
        $id = $this->approvedContract();
        $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1), new SignerSpec('b@example.com', 'Ben Beispiel', 2)]);
        $tk = $this->tokens();
        $this->assertSame(['a@example.com'], array_keys($tk));
        $this->signAs('a@example.com', $tk['a@example.com'], 'Anna Muster');
        $this->assertSame(['a@example.com', 'b@example.com'], array_keys($this->tokens()));
    }

    public function testCodeRulesAndAttemptLimit(): void
    {
        $id = $this->approvedContract();
        $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $t = $this->tokens()['a@example.com'];
        $this->assertThrows(ValidationException::class, fn () => $this->svc->sign($t, '123456', 'Anna Muster', true, '1.1.1.1', 'x'), 'ohne Code angefordert');
        $this->svc->sendCode($t);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->svc->sendCode($t), 'Wiederholung < 60 s');
        $good = $this->lastCode('a@example.com');
        $bad = $good === '000000' ? '000001' : '000000';
        $this->assertThrows(ValidationException::class, fn () => $this->svc->sign($t, $good, 'A', true, '1.1.1.1', 'x'), 'Name zu kurz');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->sign($t, $good, 'Anna Muster', false, '1.1.1.1', 'x'), 'ohne Einwilligung');
        for ($i = 0; $i < 5; $i++) {
            $this->assertThrows(ValidationException::class, fn () => $this->svc->sign($t, $bad, 'Anna Muster', true, '1.1.1.1', 'x'));
        }
        $this->assertThrows(\App\Core\TooManyRequestsException::class, fn () => $this->svc->sign($t, $good, 'Anna Muster', true, '1.1.1.1', 'x'), 'nach 5 Fehlversuchen gesperrt');
        $this->app->clock->advance(61);
        $this->svc->sendCode($t);
        $this->svc->sign($t, $this->lastCode('a@example.com'), 'Anna Muster', true, '1.1.1.1', 'x');
        $this->assertSame('active', $this->status($id));
    }

    public function testCodeExpires(): void
    {
        $id = $this->approvedContract();
        $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $t = $this->tokens()['a@example.com'];
        $this->svc->sendCode($t);
        $c = $this->lastCode('a@example.com');
        $this->app->clock->advance(901);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->sign($t, $c, 'Anna Muster', true, '1.1.1.1', 'x'));
    }

    public function testDeclineCancelsAndReleasesContract(): void
    {
        $id = $this->approvedContract();
        $rid = $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1), new SignerSpec('b@example.com', 'Ben Beispiel', 1)]);
        $t = $this->tokens();
        $this->svc->decline($t['a@example.com'], 'Nicht einverstanden', '1.1.1.1');
        $this->assertSame('declined', $this->sig->refresh($this->mgr, $rid));
        $this->assertThrows(\Throwable::class, fn () => $this->svc->sendCode($t['b@example.com']), 'Vorgang beendet');
        $this->assertTrue($this->status($id) !== 'active');
    }

    public function testWithdrawInvalidatesLinksAndUnknownTokenIsNotFound(): void
    {
        $id = $this->approvedContract();
        $rid = $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $t = $this->tokens()['a@example.com'];
        $this->sig->cancel($this->mgr, $rid, 'Irrtum');
        $this->assertThrows(\Throwable::class, fn () => $this->svc->sendCode($t), 'Link nach Rückzug unbrauchbar');
        $this->assertSame(null, $this->svc->lookup(str_repeat('x', 43)));
        $this->assertSame(null, $this->svc->lookup('kurz'));
    }

    public function testExpiryAfter30Days(): void
    {
        $id = $this->approvedContract();
        $rid = $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $t = $this->tokens()['a@example.com'];
        $this->app->clock->advance(31 * 86400);
        $this->assertThrows(BusinessRuleException::class, fn () => $this->svc->sendCode($t));
        $ext = (string) $this->db->value('SELECT external_transaction_id FROM signature_requests WHERE id = ?', [$rid]);
        $this->assertSame('expired', $this->svc->status($ext)->state);
        $this->assertSame('expired', (string) $this->db->value('SELECT state FROM signing_sessions WHERE request_ref = ?', [$rid]));
    }

    public function testTokensStoredOnlyHashed(): void
    {
        $id = $this->approvedContract();
        $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $t = $this->tokens()['a@example.com'];
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM signing_signers WHERE token_hash = ?', [$t]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM signing_signers WHERE token_hash = ?', [hash('sha256', $t)]));
    }

    public function testSignerInfoAndReminderOnlyWhileOpen(): void
    {
        $id = $this->approvedContract();
        $rid = $this->sig->request($this->mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
        $info = $this->sig->signerInfo($this->mgr, $rid, 'submitted');
        $this->assertSame(1, count($info['signer_status']));
        $this->assertSame('invited', $info['signer_status'][0]['status']);
        $this->assertTrue($info['can_remind']);
        $old = $this->tokens()['a@example.com'];
        $this->app->clock->advance(61);
        $this->assertSame(1, $this->sig->remind($this->mgr, $rid));
        $this->assertSame(null, $this->svc->lookup($old), 'alter Link ungültig');
        $new = array_values($this->tokens());
        $this->assertTrue(end($new) !== $old);
        $this->signAs('a@example.com', end($new), 'Anna Muster');
        $this->assertFalse($this->sig->signerInfo($this->mgr, $rid, 'signed')['can_remind']);
    }
}
