<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Testanbieter. Erzeugt KEINE Signaturen. Alles, was er liefert, ist als TEST gekennzeichnet
 * und wird vom SignatureService nie als gültige Signatur behandelt (Vertrag wird nicht aktiviert).
 * Der Ausgang wird über simulate() gesteuert (nur in Tests / Testbetrieb).
 */
final class SandboxProvider implements SignatureProvider
{
    /** @var array<string,array{state:string,sha:string}> */
    private array $store = [];
    public bool $failNextCreate = false;

    /** Mit Datenbank bleibt der Zustand zwischen Requests erhalten (Testbetrieb im Web); ohne nur im Speicher. */
    public function __construct(private readonly ?\App\Core\Database $db = null)
    {
    }

    private function load(string $id): ?array
    {
        if (isset($this->store[$id])) {
            return $this->store[$id];
        }
        if ($this->db === null) {
            return null;
        }
        $raw = $this->db->value('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['sandbox.sig.' . $id]);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($j) && isset($j['state'], $j['sha']) ? $this->store[$id] = $j : null;
    }

    private function save(string $id, array $row): void
    {
        $this->store[$id] = $row;
        $this->db?->run(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['sandbox.sig.' . $id, json_encode($row, JSON_THROW_ON_ERROR)],
        );
    }

    public function name(): string
    {
        return 'sandbox';
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function supportedLevels(): array
    {
        return ['SES'];
    }

    public function createRequest(ProviderRequestSpec $spec): string
    {
        if ($this->failNextCreate) {
            $this->failNextCreate = false;
            throw new ProviderException('Sandbox: simulierter Fehler', true, 503);
        }
        // Idempotenz: gleicher Schlüssel => gleiche ID
        $id = 'sandbox-' . substr(hash('sha256', $spec->idempotencyKey), 0, 16);
        if ($this->load($id) === null) {
            $this->save($id, ['state' => 'open', 'sha' => $spec->pdfSha256]);
        }
        return $id;
    }

    public function simulate(string $externalId, string $state): void
    {
        $row = $this->load($externalId);
        if ($row === null) {
            throw new ProviderException('Sandbox: unbekannte Anfrage');
        }
        $row['state'] = $state;
        $this->save($externalId, $row);
    }

    public function fetchStatus(string $externalId): ProviderStatus
    {
        $s = $this->load($externalId);
        if ($s === null) {
            throw new ProviderException('Sandbox: unbekannte Anfrage', false, 404);
        }
        return new ProviderStatus($s['state'], $s['state'] === 'signed' ? $externalId : null, ['sandbox' => true], $s['state']);
    }

    public function withdraw(string $externalId, string $reason): void
    {
        $this->simulate($externalId, 'cancelled');
    }

    public function fetchSignedDocument(string $externalId, ProviderStatus $status): ?ProviderDocument
    {
        if ($status->state !== 'signed') {
            return null;
        }
        $text = "TESTBELEG – KEINE RECHTSGÜLTIGE SIGNATUR\nSandbox-Vorgang {$externalId}\nDokument-SHA-256: " . ($this->load($externalId)['sha'] ?? '?') . "\n";
        $pdf = (new \App\Modules\Documents\PlainPdfRenderer())->render('TESTBELEG – KEINE SIGNATUR', [['type' => 'p', 'text' => $text, 'n' => null]]);
        return new ProviderDocument($pdf, 'sandbox-testbeleg.pdf', 'sandbox_receipt');
    }

    public function fetchEvidence(string $externalId, ProviderStatus $status): array
    {
        return [new EvidenceItem('provider_receipt', $externalId, ['sandbox' => true, 'hinweis' => 'Testbeleg, keine Signatur'])];
    }

    public function validate(string $signedBytes): ValidationResult
    {
        return new ValidationResult('not_validated', 'UNKNOWN', ['sandbox' => true]);
    }
}
