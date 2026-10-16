<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Anbieterneutrale Signaturschnittstelle. Konkrete Anbieter (Skribble, Swisscom Sign, …) werden
 * ausschliesslich hinter dieser Schnittstelle angebunden; die Geschäftslogik kennt keine
 * anbieterspezifischen Details.
 *
 * Verbindliche Verträge für Implementierungen:
 *  - Fehler führen zu ProviderException; es darf NIE ein Erfolg vorgetäuscht werden.
 *  - Unbekannte Anbieterwerte werden als state "unknown" gemeldet (nie als "signed").
 *  - Ein Testmodus (mode() === "sandbox") darf niemals Ergebnisse liefern, die wie eine
 *    rechtsgültige Signatur aussehen.
 */
interface SignatureProvider
{
    /** Kennung, z. B. "skribble" oder "sandbox". */
    public function name(): string;

    /** "sandbox" (Test, nicht rechtsgültig) oder "live". */
    public function mode(): string;

    /** @return list<string> vom Anbieter angebotene Stufen: SES, AES, QES */
    public function supportedLevels(): array;

    /**
     * Übermittelt Dokument und Unterzeichner. Rückgabe: externe Transaktions-ID.
     * Muss idempotent über $spec->idempotencyKey arbeiten, soweit der Anbieter dies erlaubt.
     */
    public function createRequest(ProviderRequestSpec $spec): string;

    public function fetchStatus(string $externalId): ProviderStatus;

    /** Zieht eine offene Anfrage zurück. */
    public function withdraw(string $externalId, string $reason): void;

    /** Liefert das signierte Dokument, sofern der Anbieter signed meldet; sonst null. */
    public function fetchSignedDocument(string $externalId, ProviderStatus $status): ?ProviderDocument;

    /** @return list<EvidenceItem> verfügbare Belege (kann leer sein) */
    public function fetchEvidence(string $externalId, ProviderStatus $status): array;

    /**
     * Prüft das signierte Dokument. Unterstützt der Anbieter keine Prüfung, wird
     * ValidationResult("not_validated") geliefert – niemals "valid" ohne tatsächliche Prüfung.
     */
    public function validate(string $signedBytes): ValidationResult;
}
