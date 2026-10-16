<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Eigener Signaturdienst als Anbieter hinter der neutralen Schnittstelle. Livemodus, Stufe SES.
 * Die Logik liegt in InternalSigningService; hier nur die Übersetzung auf die Schnittstelle.
 */
final class InternalSignatureProvider implements SignatureProvider, SupportsReminders, ReportsSignerStatus
{
    public function __construct(private readonly InternalSigningService $svc)
    {
    }

    public function name(): string
    {
        return 'internal';
    }

    public function mode(): string
    {
        return 'live';
    }

    public function supportedLevels(): array
    {
        return ['SES'];
    }

    public function createRequest(ProviderRequestSpec $spec): string
    {
        return $this->svc->create($spec);
    }

    public function fetchStatus(string $externalId): ProviderStatus
    {
        return $this->svc->status($externalId);
    }

    public function withdraw(string $externalId, string $reason): void
    {
        $this->svc->withdraw($externalId);
    }

    public function fetchSignedDocument(string $externalId, ProviderStatus $status): ?ProviderDocument
    {
        return $status->state === 'signed' ? $this->svc->finalDocument($externalId) : null;
    }

    public function fetchEvidence(string $externalId, ProviderStatus $status): array
    {
        return $status->state === 'signed' ? $this->svc->evidence($externalId) : [];
    }

    public function validate(string $signedBytes): ValidationResult
    {
        return $this->svc->validate($signedBytes);
    }

    public function remind(string $externalId): int
    {
        return $this->svc->remind($externalId);
    }

    public function signerStatus(string $externalId): array
    {
        return $this->svc->signerStatus($externalId);
    }
}
