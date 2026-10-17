<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\TooManyRequestsException;
use App\Core\ValidationException;
use App\Mail\Mailer;
use App\Mail\MailException;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\PdfAppender;
use App\Modules\Documents\PlainPdfRenderer;
use App\Security\RateLimiter;

/**
 * Eigener Signaturdienst: einfache elektronische Signatur (SES).
 *
 * Ablauf: Einladung per E-Mail-Link (256 Bit, nur Hash gespeichert) → Einmalcode per E-Mail (6 Stellen, 15 Min., 5 Versuche)
 * → Einwilligung + eingetragener Name → Beleg je Person (Zeit, IP, Browser). Nach der letzten Unterschrift wird ein
 * Signaturprotokoll als Anhangseiten an das PDF angehängt (Original bleibt byte-identisch Präfix) und das Gesamtdokument mit
 * Ed25519 versiegelt (Siegel der Plattform, Schlüssel aus APP_KEY abgeleitet; öffentlicher Schlüssel wird mitgespeichert).
 *
 * GRENZEN (bewusst, auch im Protokoll vermerkt): Die Identifikation beruht allein auf dem Zugriff auf die E-Mail-Adresse.
 * Das ist KEINE fortgeschrittene und KEINE qualifizierte elektronische Signatur (ZertES). Das Siegel schützt vor nachträglicher
 * Veränderung, belegt aber keine Zertifizierung durch eine Drittstelle. Wer die Plattform betreibt, könnte Siegel erzeugen.
 */
final class InternalSigningService
{
    public const CONSENT_VERSION = 'v1';
    public const CONSENT_TEXT = 'Ich habe das Dokument vollständig gelesen und bin damit einverstanden, es elektronisch zu unterzeichnen. Mir ist bewusst, dass es sich um eine einfache elektronische Signatur handelt, die ich durch Zugriff auf meine E-Mail-Adresse bestätige.';
    private const TTL_DAYS = 30;
    private const OTP_TTL = 900;
    private const OTP_MAX_ATTEMPTS = 5;
    private const OTP_RESEND_AFTER = 60;

    /** @var (\Closure(int):void)|null wird nach der letzten Unterschrift mit der Vorgangs-ID aufgerufen (Übernahme in den Vertrag) */
    private ?\Closure $onComplete = null;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly DocumentStore $store,
        private readonly string $appKey,
        private readonly string $baseUrl,
        private readonly string $appName,
    ) {
    }

    private ?TimestampService $timestamps = null;

    public function setTimestamps(?TimestampService $t): void
    {
        $this->timestamps = $t;
    }

    /** Holt fehlende Zeitstempel nach (Cron). @return int Anzahl */
    public function timestampMissing(): int
    {
        return $this->timestamps?->stampMissing() ?? 0;
    }

    /** @param \Closure(int):void $fn */
    public function onComplete(\Closure $fn): void
    {
        $this->onComplete = $fn;
    }

    // ------------------------------------------------------------------ Seite der Anfragenden (über den Provider)

    /** @return string externe ID */
    public function create(ProviderRequestSpec $spec): string
    {
        if (!str_starts_with($spec->pdfBytes, '%PDF-') || !hash_equals($spec->pdfSha256, hash('sha256', $spec->pdfBytes))) {
            throw new ProviderException('Dokument und Hash stimmen nicht überein.');
        }
        $ext = bin2hex(random_bytes(16));
        $existing = $this->db->one('SELECT external_id FROM signing_sessions WHERE request_ref = ?', [(int) $spec->reference]);
        if ($existing !== null) {
            return (string) $existing['external_id']; // idempotent
        }
        $now = $this->clock->now();
        $this->db->transaction(function () use ($spec, $ext, $now): void {
            $this->db->run(
                'INSERT INTO signing_sessions (external_id, request_ref, title, message, pdf_sha256, expires_at) VALUES (?,?,?,?,?,?)',
                [$ext, (int) $spec->reference, mb_substr($spec->title, 0, 255), $spec->message !== '' ? mb_substr($spec->message, 0, 2000) : null, $spec->pdfSha256,
                    $now->modify('+' . self::TTL_DAYS . ' days')->format('Y-m-d H:i:s.u')],
            );
            $sid = $this->db->lastId();
            foreach ($spec->signers as $i => $s) {
                $this->db->run(
                    'INSERT INTO signing_signers (session_id, position, order_no, name, email) VALUES (?,?,?,?,?)',
                    [$sid, $i + 1, max(1, min(10, $s->order)), mb_substr(trim($s->name), 0, 190), mb_substr(strtolower(trim($s->email)), 0, 190)],
                );
            }
        });
        $sid = (int) $this->db->value('SELECT id FROM signing_sessions WHERE external_id = ?', [$ext]);
        try {
            $this->inviteNextGroup($sid);
        } catch (\Throwable $e) {
            $this->withdraw($ext); // kein offener Vorgang mit gültigen Links zurücklassen
            throw $e;
        }
        return $ext;
    }

    /** @return list<array<string,mixed>> */
    private function signers(int $sid): array
    {
        return $this->db->all('SELECT * FROM signing_signers WHERE session_id = ? ORDER BY order_no, position', [$sid]);
    }

    /** @return array<string,mixed> */
    private function session(string $externalId): array
    {
        $s = $this->db->one('SELECT * FROM signing_sessions WHERE external_id = ?', [$externalId]);
        if ($s === null) {
            throw new ProviderException('Unbekannter Signaturvorgang', false, 404);
        }
        return $s;
    }

    public function status(string $externalId): ProviderStatus
    {
        $s = $this->session($externalId);
        if ($s['state'] === 'open' && $s['expires_at'] < $this->clock->now()->format('Y-m-d H:i:s.u')) {
            $this->db->run("UPDATE signing_sessions SET state = 'expired' WHERE id = ? AND state = 'open'", [(int) $s['id']]);
            $this->db->run("UPDATE signing_signers SET token_hash = NULL WHERE session_id = ? AND status IN ('waiting','invited')", [(int) $s['id']]);
            $s['state'] = 'expired';
        }
        return new ProviderStatus((string) $s['state'], $s['state'] === 'signed' ? $externalId : null, ['internal' => true], (string) $s['state']);
    }

    public function withdraw(string $externalId): void
    {
        $s = $this->session($externalId);
        $this->db->transaction(function () use ($s): void {
            $this->db->run("UPDATE signing_sessions SET state = 'cancelled' WHERE id = ? AND state = 'open'", [(int) $s['id']]);
            $this->db->run("UPDATE signing_signers SET token_hash = NULL WHERE session_id = ? AND status IN ('waiting','invited')", [(int) $s['id']]);
        });
    }

    public function finalDocument(string $externalId): ProviderDocument
    {
        $s = $this->session($externalId);
        if ($s['state'] !== 'signed' || $s['final_pdf'] === null) {
            throw new ProviderException('Das Dokument ist noch nicht vollständig unterzeichnet.');
        }
        return new ProviderDocument((string) $s['final_pdf'], 'unterzeichnet-' . substr($externalId, 0, 8) . '.pdf', 'signed');
    }

    /** @return list<EvidenceItem> */
    public function evidence(string $externalId): array
    {
        $s = $this->session($externalId);
        if ($s['state'] !== 'signed') {
            return [];
        }
        $out = [new EvidenceItem('provider_receipt', $externalId, [
            'verfahren' => 'Einfache elektronische Signatur (SES) der Plattform: E-Mail-Link + Einmalcode, Einwilligung',
            'siegel' => 'Ed25519', 'siegel_signatur' => $s['seal_signature'], 'oeffentlicher_schluessel' => $s['seal_public_key'],
            'dokument_sha256' => $s['final_sha256'], 'urspruengliches_dokument_sha256' => $s['pdf_sha256'], 'urspruengliche_laenge' => (int) $s['original_length'],
        ])];
        if ($s['tsa_token'] !== null) {
            $out[] = new EvidenceItem('timestamp', $externalId, [
                'zeitstempel_utc' => $s['tsa_gen_time'], 'zeitstempelstelle' => $s['tsa_url'], 'seriennummer' => $s['tsa_serial'],
                'norm' => 'RFC 3161 über den SHA-256 des versiegelten Dokuments', 'dokument_sha256' => $s['final_sha256'],
                'hinweis' => 'Token-Signatur geprüft; Zertifikatskette der Zeitstempelstelle nicht in der Plattform geprüft.',
            ], (string) $s['tsa_token']);
        }
        foreach ($this->signers((int) $s['id']) as $g) {
            $out[] = new EvidenceItem('provider_receipt', $externalId . '#' . $g['position'], [
                'unterzeichner' => $g['name'], 'email' => $g['email'], 'eingetragener_name' => $g['signed_name'], 'zeit_utc' => $g['signed_at'],
                'ip' => $g['ip'], 'einwilligung' => $g['consent_version'],
            ]);
        }
        return $out;
    }

    /** Prüft Siegel und Unverändertheit. Liefert nur dann «valid», wenn alles nachgerechnet wurde. */
    public function validate(string $bytes): ValidationResult
    {
        $sha = hash('sha256', $bytes);
        $s = $this->db->one("SELECT * FROM signing_sessions WHERE final_sha256 = ? AND state = 'signed'", [$sha]);
        if ($s === null) {
            return new ValidationResult('not_validated', 'UNKNOWN', ['grund' => 'Dieses Dokument ist der Plattform nicht als versiegelt bekannt.']);
        }
        $sig = base64_decode((string) $s['seal_signature'], true);
        $pub = hex2bin((string) $s['seal_public_key']);
        $okSeal = $sig !== false && $pub !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($sig, hash('sha256', $bytes, true), $pub);
        $len = (int) $s['original_length'];
        $okOriginal = $len > 0 && hash_equals((string) $s['pdf_sha256'], hash('sha256', substr($bytes, 0, $len)));
        if (!$okSeal || !$okOriginal) {
            return new ValidationResult('invalid', 'UNKNOWN', ['siegel' => $okSeal, 'original_unveraendert' => $okOriginal]);
        }
        return new ValidationResult('valid', 'SES', ['siegel' => true, 'original_unveraendert' => true, 'hinweis' => 'Siegel der Plattform geprüft; keine Zertifizierung durch eine Drittstelle.']);
    }

    /** @return list<array{name:string,email:string,status:string,at:?string,order:int}> */
    public function signerStatus(string $externalId): array
    {
        $s = $this->session($externalId);
        return array_map(static fn (array $g): array => [
            'name' => (string) $g['name'], 'email' => (string) $g['email'], 'status' => (string) $g['status'],
            'at' => $g['status'] === 'signed' ? (string) $g['signed_at'] : null, 'order' => (int) $g['order_no'],
        ], $this->signers((int) $s['id']));
    }

    public function remind(string $externalId): int
    {
        $s = $this->session($externalId);
        if ($s['state'] !== 'open') {
            throw new BusinessRuleException('Der Vorgang ist nicht mehr offen.');
        }
        $n = 0;
        foreach ($this->signers((int) $s['id']) as $g) {
            if ($g['status'] === 'invited') {
                $this->sendInvitation($s, $g);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ Einladung

    /** Lädt alle Personen der kleinsten noch offenen Reihenfolgegruppe ein. */
    private function inviteNextGroup(int $sid): void
    {
        $s = $this->db->one('SELECT * FROM signing_sessions WHERE id = ?', [$sid]);
        if ($s === null || $s['state'] !== 'open') {
            return;
        }
        $pending = array_values(array_filter($this->signers($sid), static fn (array $g): bool => in_array($g['status'], ['waiting', 'invited'], true)));
        if ($pending === []) {
            return;
        }
        $group = min(array_map(static fn (array $g): int => (int) $g['order_no'], $pending));
        foreach ($pending as $g) {
            if ((int) $g['order_no'] === $group && $g['status'] === 'waiting') {
                $this->sendInvitation($s, $g);
            }
        }
    }

    /** @param array<string,mixed> $s @param array<string,mixed> $g */
    private function sendInvitation(array $s, array $g): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->run(
            "UPDATE signing_signers SET token_hash = ?, status = 'invited', invited_at = ?, otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?",
            [hash('sha256', $token), $this->clock->now()->format('Y-m-d H:i:s.u'), (int) $g['id']],
        );
        $until = (new \DateTimeImmutable((string) $s['expires_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('d.m.Y');
        $body = "Guten Tag {$g['name']}\n\nSie wurden gebeten, ein Dokument elektronisch zu unterzeichnen:\n\n  {$s['title']}\n\n"
            . ($s['message'] !== null && $s['message'] !== '' ? "Mitteilung des Absenders:\n" . $s['message'] . "\n\n" : '')
            . "Dokument ansehen und unterzeichnen:\n" . rtrim($this->baseUrl, '/') . '/unterschreiben/' . $token . "\n\n"
            . "Der Link ist persönlich, bitte nicht weiterleiten. Er gilt bis {$until}. Zur Unterzeichnung erhalten Sie auf der Seite einen Einmalcode an diese E-Mail-Adresse.\n"
            . 'Falls Sie diese Anfrage nicht erwarten, ignorieren Sie diese E-Mail.';
        try {
            $this->mailer->send((string) $g['email'], (string) $g['name'], $this->appName . ': Bitte unterzeichnen – ' . mb_substr((string) $s['title'], 0, 80), $body);
        } catch (MailException) {
            $this->audit->log('mail.send_failed', 'failure', null, null, null, null, ['subject_class' => 'signing_invite']);
            throw new ProviderException('Die Einladung konnte nicht per E-Mail versendet werden. Bitte E-Mail-Einstellungen prüfen.', true, 503);
        }
    }

    // ------------------------------------------------------------------ Seite der Unterzeichnenden (öffentlicher Link)

    /** @return array{session:array<string,mixed>,signer:array<string,mixed>}|null null = Link ungültig/abgelaufen */
    public function lookup(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $g = $this->db->one('SELECT * FROM signing_signers WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($g === null) {
            return null;
        }
        $s = (array) $this->db->one('SELECT id, external_id, request_ref, title, message, pdf_sha256, state, expires_at, completed_at, final_sha256 FROM signing_sessions WHERE id = ?', [(int) $g['session_id']]);
        if ($s['state'] === 'open' && $s['expires_at'] < $this->clock->now()->format('Y-m-d H:i:s.u')) {
            $s['state'] = 'expired';
        }
        return ['session' => $s, 'signer' => $g];
    }

    /** @return list<array{name:string,status:string}> ohne E-Mail-Adressen */
    public function publicSigners(int $sessionId): array
    {
        return array_map(static fn (array $g): array => ['name' => (string) $g['name'], 'status' => (string) $g['status']], $this->signers($sessionId));
    }

    public function markViewed(int $signerId): void
    {
        $this->db->run('UPDATE signing_signers SET viewed_at = COALESCE(viewed_at, ?) WHERE id = ?', [$this->clock->now()->format('Y-m-d H:i:s.u'), $signerId]);
    }

    /** Bytes des Originals (offen) bzw. des fertigen Dokuments (abgeschlossen). */
    public function documentFor(array $session): string
    {
        if ($session['state'] === 'signed') {
            $row = (array) $this->db->one('SELECT final_pdf FROM signing_sessions WHERE id = ?', [(int) $session['id']]);
            return (string) $row['final_pdf'];
        }
        $doc = $this->db->one(
            'SELECT d.* FROM signature_requests r JOIN documents d ON d.id = r.document_id WHERE r.id = ? AND r.document_sha256 = ?',
            [(int) $session['request_ref'], (string) $session['pdf_sha256']],
        );
        if ($doc === null) {
            throw new NotFoundException();
        }
        $bytes = $this->store->readBytes($doc);
        if (!hash_equals((string) $session['pdf_sha256'], hash('sha256', $bytes))) {
            throw new BusinessRuleException('Integritätsprüfung des Dokuments fehlgeschlagen.');
        }
        return $bytes;
    }

    public function sendCode(string $token): void
    {
        $x = $this->open($token);
        $g = $x['signer'];
        $this->limiter->assertBelow(RateLimiter::bucket('sigcode', (string) $g['id']), 6, 3600);
        if ($g['otp_sent_at'] !== null && strtotime((string) $g['otp_sent_at'] . ' UTC') > $this->clock->now()->getTimestamp() - self::OTP_RESEND_AFTER) {
            throw new BusinessRuleException('Bitte warten Sie eine Minute, bevor Sie einen neuen Code anfordern.');
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $this->clock->now();
        $this->db->run(
            'UPDATE signing_signers SET otp_hash = ?, otp_expires_at = ?, otp_sent_at = ?, otp_attempts = 0 WHERE id = ?',
            [$this->otpHash((int) $g['id'], $code), $now->modify('+' . self::OTP_TTL . ' seconds')->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u'), (int) $g['id']],
        );
        $this->limiter->hit(RateLimiter::bucket('sigcode', (string) $g['id']));
        try {
            $this->mailer->send((string) $g['email'], (string) $g['name'], $this->appName . ': Ihr Code zum Unterzeichnen',
                "Guten Tag {$g['name']}\n\nIhr Einmalcode zum Unterzeichnen von «{$x['session']['title']}»:\n\n    {$code}\n\nDer Code ist 15 Minuten gültig. Geben Sie ihn nur auf der Unterzeichnungsseite ein, nie weiter.\nFalls Sie keinen Code angefordert haben, ignorieren Sie diese E-Mail.");
        } catch (MailException) {
            throw new BusinessRuleException('Der Code konnte nicht per E-Mail versendet werden. Bitte den Absender informieren.');
        }
        $this->audit->log('signing.code_sent', 'success', null, null, 'signature_request', (int) $x['session']['request_ref'], ['signer' => (int) $g['position']]);
    }

    public function sign(string $token, string $code, string $typedName, bool $consent, string $ip, string $userAgent): void
    {
        $x = $this->open($token);
        $g = $x['signer'];
        $errors = [];
        $typedName = trim(preg_replace('/\s+/u', ' ', $typedName) ?? '');
        if (mb_strlen($typedName) < 2 || mb_strlen($typedName) > 190) {
            $errors['name'] = 'Bitte Ihren vollständigen Namen eintragen.';
        }
        if (!$consent) {
            $errors['consent'] = 'Bitte bestätigen Sie die Einwilligung.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->checkCode($g, $code);

        $signed = $this->db->transaction(function () use ($g, $typedName, $ip, $userAgent): bool {
            $cur = $this->db->one('SELECT status FROM signing_signers WHERE id = ? FOR UPDATE', [(int) $g['id']]);
            if ($cur === null || $cur['status'] !== 'invited') {
                return false;
            }
            $this->db->run(
                "UPDATE signing_signers SET status = 'signed', signed_at = ?, signed_name = ?, ip = ?, user_agent = ?, consent_version = ?, otp_hash = NULL, otp_expires_at = NULL WHERE id = ?",
                [$this->clock->now()->format('Y-m-d H:i:s.u'), $typedName, mb_substr($ip, 0, 45), mb_substr($userAgent, 0, 190), self::CONSENT_VERSION, (int) $g['id']],
            );
            return true;
        });
        if (!$signed) {
            throw new BusinessRuleException('Sie haben dieses Dokument bereits bearbeitet.');
        }
        $this->audit->log('signing.signed', 'success', null, null, 'signature_request', (int) $x['session']['request_ref'], ['signer' => (int) $g['position']]);
        $sid = (int) $x['session']['id'];
        $left = (int) $this->db->value("SELECT COUNT(*) FROM signing_signers WHERE session_id = ? AND status <> 'signed'", [$sid]);
        if ($left === 0) {
            $this->finalize($sid);
            if ($this->onComplete !== null) {
                try {
                    ($this->onComplete)((int) $x['session']['request_ref']);
                } catch (\Throwable) {
                    // Die Übernahme in den Vertrag wiederholt der Cron; die Unterschrift selbst steht fest.
                }
            }
        } else {
            $this->inviteNextGroup($sid);
        }
    }

    public function decline(string $token, string $reason, string $ip): void
    {
        $x = $this->open($token);
        $g = $x['signer'];
        $reason = mb_substr(trim($reason), 0, 500);
        $this->db->transaction(function () use ($x, $g, $reason, $ip): void {
            $this->db->run("UPDATE signing_signers SET status = 'declined', decline_reason = ?, ip = ?, token_hash = NULL WHERE id = ? AND status = 'invited'", [$reason !== '' ? $reason : null, mb_substr($ip, 0, 45), (int) $g['id']]);
            $this->db->run("UPDATE signing_sessions SET state = 'declined' WHERE id = ? AND state = 'open'", [(int) $x['session']['id']]);
            $this->db->run("UPDATE signing_signers SET token_hash = NULL WHERE session_id = ? AND status IN ('waiting','invited')", [(int) $x['session']['id']]);
        });
        $this->audit->log('signing.declined', 'success', null, null, 'signature_request', (int) $x['session']['request_ref'], ['signer' => (int) $g['position']]);
        if ($this->onComplete !== null) {
            try {
                ($this->onComplete)((int) $x['session']['request_ref']);
            } catch (\Throwable) {
            }
        }
    }

    /** @return array{session:array<string,mixed>,signer:array<string,mixed>} */
    private function open(string $token): array
    {
        $x = $this->lookup($token);
        if ($x === null) {
            throw new NotFoundException();
        }
        if ($x['session']['state'] !== 'open' || $x['signer']['status'] !== 'invited') {
            throw new BusinessRuleException('Dieser Vorgang ist nicht mehr offen oder wurde von Ihnen bereits bearbeitet.');
        }
        return $x;
    }

    /** @param array<string,mixed> $g */
    private function checkCode(array $g, string $code): void
    {
        $fresh = (array) $this->db->one('SELECT otp_hash, otp_expires_at, otp_attempts FROM signing_signers WHERE id = ?', [(int) $g['id']]);
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($fresh['otp_hash'] === null || $fresh['otp_expires_at'] < $this->clock->now()->format('Y-m-d H:i:s.u')) {
            throw new ValidationException(['code' => 'Bitte zuerst einen Code anfordern (der letzte ist abgelaufen oder fehlt).']);
        }
        if ((int) $fresh['otp_attempts'] >= self::OTP_MAX_ATTEMPTS) {
            throw new TooManyRequestsException('Zu viele falsche Eingaben. Bitte fordern Sie einen neuen Code an.');
        }
        if (strlen($code) !== 6 || !hash_equals((string) $fresh['otp_hash'], $this->otpHash((int) $g['id'], $code))) {
            $this->db->run('UPDATE signing_signers SET otp_attempts = otp_attempts + 1 WHERE id = ?', [(int) $g['id']]);
            throw new ValidationException(['code' => 'Der Code ist nicht korrekt.']);
        }
    }

    private function otpHash(int $signerId, string $code): string
    {
        return hash_hmac('sha256', $signerId . '|' . $code, 'otp|' . $this->appKey);
    }

    // ------------------------------------------------------------------ Abschluss: Protokoll + Siegel

    /** @return array{0:string,1:string} geheimer, öffentlicher Schlüssel */
    private function sealKeys(): array
    {
        $kp = sodium_crypto_sign_seed_keypair(hash('sha256', 'vp-seal-v1|' . $this->appKey, true));
        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    public function finalize(int $sid): void
    {
        $this->db->transaction(function () use ($sid): void {
            $s = $this->db->one('SELECT * FROM signing_sessions WHERE id = ? FOR UPDATE', [$sid]);
            if ($s === null || $s['state'] !== 'open' || $s['final_pdf'] !== null) {
                return;
            }
            $signers = $this->signers($sid);
            foreach ($signers as $g) {
                if ($g['status'] !== 'signed') {
                    throw new BusinessRuleException('Nicht alle haben unterzeichnet.');
                }
            }
            $original = $this->documentFor($s);
            [$secret, $public] = $this->sealKeys();
            $fingerprint = substr(hash('sha256', $public), 0, 16);
            $pages = (new PlainPdfRenderer())->appendixContents('Signaturprotokoll', $this->protocolBlocks($s, $signers, $fingerprint), 'Signaturprotokoll · ' . mb_substr((string) $s['title'], 0, 60));
            $final = PdfAppender::append($original, $pages);
            $sig = sodium_crypto_sign_detached(hash('sha256', $final, true), $secret);
            $this->db->run(
                "UPDATE signing_sessions SET state = 'signed', final_pdf = ?, final_sha256 = ?, original_length = ?, seal_signature = ?, seal_public_key = ?, completed_at = ? WHERE id = ?",
                [$final, hash('sha256', $final), strlen($original), base64_encode($sig), bin2hex($public), $this->clock->now()->format('Y-m-d H:i:s.u'), $sid],
            );
            $this->audit->log('signing.sealed', 'success', null, null, 'signature_request', (int) $s['request_ref'], ['document_sha256' => hash('sha256', $final), 'key' => $fingerprint]);
        });
        $this->timestamps?->stamp($sid); // ausserhalb der Transaktion; Fehler blockieren nie
    }

    /**
     * @param array<string,mixed> $s
     * @param list<array<string,mixed>> $signers
     * @return list<array{type:string,text:string,n:?int}>
     */
    private function protocolBlocks(array $s, array $signers, string $fingerprint): array
    {
        $b = [];
        $p = static function (string $t) use (&$b): void { $b[] = ['type' => 'p', 'text' => $t, 'n' => null]; };
        $h = static function (string $t) use (&$b): void { $b[] = ['type' => 'h2', 'text' => $t, 'n' => null]; };
        $zone = new \DateTimeZone('Europe/Zurich');
        $fmt = static function (?string $utc) use ($zone): string {
            if ($utc === null) {
                return '–';
            }
            $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $d->format('d.m.Y H:i:s') . ' UTC (' . $d->setTimezone($zone)->format('d.m.Y H:i') . ' Schweizer Zeit)';
        };
        $h('Dokument');
        $p('Titel: ' . $s['title']);
        $p('Vorgang: #' . (int) $s['request_ref']);
        $p('SHA-256 des unterzeichneten Dokuments (vor diesem Protokoll): ' . $s['pdf_sha256']);
        $h('Art der Signatur');
        $p('Einfache elektronische Signatur (SES) der Plattform. Die Unterzeichnenden wurden über den Zugriff auf die angegebene E-Mail-Adresse identifiziert (persönlicher Link und Einmalcode). Es handelt sich weder um eine fortgeschrittene noch um eine qualifizierte elektronische Signatur nach ZertES. Ob diese Signaturart für den vorliegenden Vertrag genügt, ist fachlich zu prüfen.');
        $h('Unterzeichnende');
        foreach ($signers as $g) {
            $p($g['position'] . '. ' . $g['name'] . ' <' . $g['email'] . '>');
            $p('Unterzeichnet als: ' . $g['signed_name'] . ' · Zeitpunkt: ' . $fmt((string) $g['signed_at']));
            $p('IP-Adresse: ' . ($g['ip'] ?: '–') . ' · Einwilligung (Text ' . $g['consent_version'] . ') erteilt · Einmalcode bestätigt');
        }
        $h('Einwilligungstext');
        $p(self::CONSENT_TEXT);
        $h('Siegel');
        $p('Nach Abschluss wurde das Gesamtdokument (Original und dieses Protokoll) mit einem Siegel der Plattform versehen (Ed25519 über den SHA-256 des Gesamtdokuments). Das Siegel liegt getrennt vom Dokument in der Plattform und kann dort geprüft werden. Schlüssel-Fingerabdruck: ' . $fingerprint . '. Das Siegel schützt vor nachträglicher Veränderung; es ist keine Zertifizierung durch eine unabhängige Stelle.');
        return $b;
    }
}
