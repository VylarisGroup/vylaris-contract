<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Signatures\HttpTransport;
use App\Modules\Signatures\InternalSignatureProvider;
use App\Modules\Signatures\InternalSigningService;
use App\Modules\Signatures\ProviderException;
use App\Modules\Signatures\Rfc3161;
use App\Modules\Signatures\SignatureService;
use App\Modules\Signatures\SignerSpec;
use App\Modules\Signatures\TimestampService;
use Tests\Support\TestApp;
use Tests\TestCase;

/** Gegen eine lokale Zeitstempelstelle auf Basis des openssl-Kommandos (kein Netzwerk). */
final class TimestampTest extends TestCase
{
    private string $dir;
    private TestApp $app;
    public int $calls = 0;
    public string $mode = 'ok';

    public function setUp(): void
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->fail('openssl-Kommando fehlt');
        }
        $this->dir = sys_get_temp_dir() . '/tsa_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $d = escapeshellarg($this->dir);
        file_put_contents($this->dir . '/req.cnf', "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Test TSA\n[v3]\nbasicConstraints=CA:FALSE\nextendedKeyUsage=critical,timeStamping\n");
        file_put_contents($this->dir . '/ts.cnf', "oid_section=oids\n[oids]\ntsa_policy1=1.2.3.4.1\n[tsa]\ndefault_tsa=tsa_config1\n[tsa_config1]\nserial=$this->dir/serial\nsigner_cert=$this->dir/c.pem\nsigner_key=$this->dir/k.pem\ndefault_policy=tsa_policy1\ndigests=sha256\nsigner_digest=sha256\n");
        file_put_contents($this->dir . '/serial', "01\n");
        shell_exec("cd $d && openssl req -x509 -newkey rsa:2048 -nodes -keyout k.pem -out c.pem -days 2 -extensions v3 -config req.cnf 2>&1");
        if (!is_file($this->dir . '/c.pem')) {
            $this->fail('Test-Zertifikat konnte nicht erzeugt werden');
        }
        $this->app = new TestApp($this->db);
    }

    public function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->cleanup();
        }
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private function tsa(): HttpTransport
    {
        $t = $this;
        return new class ($t) implements HttpTransport {
            public function __construct(private readonly TimestampTest $t)
            {
            }

            public function request(string $method, string $url, array $headers, ?string $body): array
            {
                return $this->t->reply($body ?? '');
            }
        };
    }

    /** @return array{status:int,body:string} */
    public function reply(string $query): array
    {
        $this->calls++;
        if ($this->mode === 'down') {
            return ['status' => 503, 'body' => ''];
        }
        $q = $this->dir . '/q.tsq';
        $r = $this->dir . '/r.tsr';
        file_put_contents($q, $query);
        @unlink($r);
        shell_exec('openssl ts -reply -config ' . escapeshellarg($this->dir . '/ts.cnf') . ' -section tsa_config1 -queryfile ' . escapeshellarg($q) . ' -out ' . escapeshellarg($r) . ' 2>&1');
        $body = (string) @file_get_contents($r);
        if ($this->mode === 'garbage') {
            $body = substr($body, 0, 40) . random_bytes(20);
        }
        return ['status' => 200, 'body' => $body];
    }

    public function testRequestIsAcceptedByOpenssl(): void
    {
        $h = hash('sha256', 'abc', true);
        file_put_contents($this->dir . '/q.tsq', Rfc3161::request($h, 123456789));
        $out = (string) shell_exec('openssl ts -query -in ' . escapeshellarg($this->dir . '/q.tsq') . ' -text 2>&1');
        $this->assertTrue(str_contains($out, 'Hash Algorithm: sha256'));
        $this->assertTrue(str_contains($out, 'Nonce: 0x075BCD15'));
        $this->assertTrue(str_contains($out, 'Certificate required: yes'));
    }

    public function testResponseParsedAndChecked(): void
    {
        $h = hash('sha256', 'dokument', true);
        $nonce = 987654321;
        $res = $this->reply(Rfc3161::request($h, $nonce));
        $r = Rfc3161::parseResponse($res['body'], $h, $nonce);
        $this->assertTrue($r['signature_ok']);
        $this->assertTrue(abs(time() - $r['gen_time']->getTimestamp()) < 120);
        $this->assertTrue($r['serial'] !== '');
        // extern prüfbar: Token ist gültiges Token der Stelle
        file_put_contents($this->dir . '/t.tst', $r['token']);
        $ver = (string) shell_exec('openssl ts -verify -in ' . escapeshellarg($this->dir . '/t.tst') . ' -token_in -digest ' . bin2hex($h) . ' -sha256 -CAfile ' . escapeshellarg($this->dir . '/c.pem') . ' 2>&1');
        $this->assertTrue(str_contains($ver, 'Verification: OK'));

        foreach ([[hash('sha256', 'anderes', true), $nonce], [$h, $nonce + 1]] as [$hh, $nn]) {
            try {
                Rfc3161::parseResponse($res['body'], $hh, $nn);
                $this->fail('muss abgelehnt werden');
            } catch (ProviderException) {
                $this->assertTrue(true);
            }
        }
        $this->assertThrows(ProviderException::class, fn () => Rfc3161::parseResponse('nonsense', $h, $nonce));
    }

    public function testManipulatedTokenFailsSignatureCheck(): void
    {
        $h = hash('sha256', 'x', true);
        $res = $this->reply(Rfc3161::request($h, 55));
        $r = Rfc3161::parseResponse($res['body'], $h, 55);
        $t = $r['token'];
        $i = strpos($t, "\x04\x20" . 'x') ?: 0;
        $this->assertTrue(Rfc3161::verifySignature($t));
        $bad = $t;
        $bad[strlen($bad) - 5] = $bad[strlen($bad) - 5] === "\x01" ? "\x02" : "\x01"; // Signaturbytes
        $this->assertFalse(Rfc3161::verifySignature($bad));
    }

    public function testSealedDocumentGetsTimestampAndFailureNeverBlocks(): void
    {
        $tenant = $this->app->fx->tenant();
        $mgr = $this->app->actor($tenant, 'contract_manager');
        $svc = new InternalSigningService($this->db, $this->app->clock, $this->app->audit, $this->app->mailer, $this->app->limiter, $this->app->store, str_repeat('k', 32), 'https://test.invalid', 'Test');
        $sig = new SignatureService($this->db, $this->app->ac, $this->app->audit, $this->app->workflow, $this->app->documents, $this->app->store, new InternalSignatureProvider($svc));
        $svc->onComplete(fn (int $rid) => $sig->syncRequest($rid));
        $ts = new TimestampService($this->db, $this->app->clock, $this->app->audit, $this->tsa(), 'https://tsa.test.invalid/ts');
        $svc->setTimestamps($ts);

        $go = function (string $mode) use ($tenant, $mgr, $svc, $sig): int {
            $this->mode = $mode;
            $this->app->mailer->sent = [];
            $appr = $this->app->actor($tenant, 'approver');
            $id = $this->app->contracts->create($mgr, $this->app->contractInput());
            $this->app->contracts->saveBody($mgr, $id, '<p>Text</p>');
            $vid = $this->app->approvals->request($mgr, $id, [[$appr->userId]]);
            $this->app->approvals->decide($appr, (int) $this->db->value('SELECT id FROM approvals WHERE contract_version_id = ?', [$vid]), 'approve');
            $rid = $sig->request($mgr, $id, 'SES', [new SignerSpec('a@example.com', 'Anna Muster', 1)]);
            preg_match('~/unterschreiben/([A-Za-z0-9_-]{43})~', $this->app->mailer->sent[0]['body'], $x);
            $svc->sendCode($x[1]);
            $code = '';
            foreach (array_reverse($this->app->mailer->sent) as $m) {
                if (preg_match('~\n\s+(\d{6})\n~', $m['body'], $c)) {
                    $code = $c[1];
                    break;
                }
            }
            $svc->sign($x[1], $code, 'Anna Muster', true, '203.0.113.5', 'T');
            return $rid;
        };

        $rid = $go('down');
        $this->assertSame('active', (string) $this->db->value('SELECT c.status FROM contracts c JOIN signature_requests r ON r.contract_id = c.id WHERE r.id = ?', [$rid]), 'Zeitstempel-Ausfall blockiert nicht');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'timestamp'", [$rid]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_events WHERE action = 'signing.timestamped' AND outcome = 'failure'"));

        $rid2 = $go('garbage');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'timestamp'", [$rid2]));

        $rid3 = $go('ok');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM signature_evidence WHERE signature_request_id = ? AND evidence_type = 'timestamp'", [$rid3]));
        $this->assertTrue($this->db->value('SELECT tsa_token FROM signing_sessions WHERE request_ref = ?', [$rid3]) !== null);

        $this->assertSame(0, $ts->stampMissing() - 2, 'zwei fehlende werden nachgeholt');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM signing_sessions WHERE tsa_token IS NOT NULL'));
    }
}
