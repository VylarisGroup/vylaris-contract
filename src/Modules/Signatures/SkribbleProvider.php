<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/**
 * Skribble-Adapter (REST API v2).
 *
 * VERIFIZIERT (offizielle Dokumentation / Postman-Sammlung, nicht gegen Live-/Demo-API getestet):
 *   POST /access/login            -> JWT (kurzlebig, ca. 20 Min.)
 *   POST /signature-requests      (title, message, content[base64] , signatures[].account_email, ...)
 *   GET  /signature-requests/{id} (liefert u. a. document_id und Status)
 *   POST /signature-requests/{id}/withdraw
 *   GET  /documents/{docId}/content (signierte Dokumente erhalten eine neue Dokument-ID)
 *
 * NICHT VERIFIZIERT und daher bewusst konservativ behandelt:
 *   - exakte Statuswerte (unbekannte Werte => state "unknown", nie "signed"),
 *   - Feldname für die gewünschte Signaturstufe ("quality"),
 *   - Signaturbericht/Prüf-Endpunkte (validate() liefert immer "not_validated"),
 *   - Demo-Basis-URL (muss in der Konfiguration explizit gesetzt werden, kein Default).
 * Vor Produktivbetrieb ist ein Test gegen die Demo-Umgebung zwingend (docs/SIGNATURE_PROVIDER.md).
 */
final class SkribbleProvider implements SignatureProvider
{
    private ?string $jwt = null;
    private int $jwtAt = 0;

    /** @param array<string,string> $statusMap zusätzliche, nach Test bestätigte Statuszuordnung */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $apiKey,
        private readonly string $mode = 'sandbox',
        private readonly array $statusMap = [],
        private readonly ?\Closure $nowFn = null,
    ) {
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('Ungültiger Modus.');
        }
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new \InvalidArgumentException('Basis-URL muss HTTPS sein.');
        }
    }

    public function name(): string
    {
        return 'skribble';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function supportedLevels(): array
    {
        return ['SES', 'AES', 'QES'];
    }

    public function createRequest(ProviderRequestSpec $spec): string
    {
        $signatures = [];
        foreach ($spec->signers as $s) {
            $signatures[] = ['account_email' => $s->email];
        }
        $payload = [
            'title' => $spec->title,
            'message' => $spec->message,
            'content' => base64_encode($spec->pdfBytes),
            'signatures' => $signatures,
            'quality' => $spec->level,
        ];
        $r = $this->call('POST', '/signature-requests', json_encode($payload, JSON_THROW_ON_ERROR));
        $j = $this->json($r['body']);
        $id = $j['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ProviderException('Antwort des Anbieters enthält keine Vorgangs-ID.');
        }
        return $id;
    }

    public function fetchStatus(string $externalId): ProviderStatus
    {
        $r = $this->call('GET', '/signature-requests/' . rawurlencode($externalId), null);
        $j = $this->json($r['body']);
        $raw = isset($j['status_overall']) && is_string($j['status_overall']) ? $j['status_overall'] : null;
        $map = $this->statusMap + [
            'OPEN' => 'open', 'SIGNED' => 'signed', 'DECLINED' => 'declined',
            'WITHDRAWN' => 'cancelled', 'EXPIRED' => 'expired',
        ];
        $state = $raw !== null ? ($map[strtoupper($raw)] ?? 'unknown') : 'unknown';
        $doc = isset($j['document_id']) && is_string($j['document_id']) ? $j['document_id'] : null;
        return new ProviderStatus($state, $state === 'signed' ? $doc : null, ['payload_sha256' => hash('sha256', $r['body'])], $raw);
    }

    public function withdraw(string $externalId, string $reason): void
    {
        $this->call('POST', '/signature-requests/' . rawurlencode($externalId) . '/withdraw', '{}');
    }

    public function fetchSignedDocument(string $externalId, ProviderStatus $status): ?ProviderDocument
    {
        if ($status->state !== 'signed' || $status->signedDocumentRef === null) {
            return null;
        }
        $r = $this->call('GET', '/documents/' . rawurlencode($status->signedDocumentRef) . '/content', null, 'application/pdf');
        return new ProviderDocument($r['body'], 'signiert.pdf', 'signed');
    }

    public function fetchEvidence(string $externalId, ProviderStatus $status): array
    {
        // Signaturbericht-Endpunkt nicht verifiziert => nur ein Beleg über die Statusantwort.
        return [new EvidenceItem('provider_receipt', $externalId, $status->detail)];
    }

    public function validate(string $signedBytes): ValidationResult
    {
        return new ValidationResult('not_validated', 'UNKNOWN', ['hinweis' => 'Keine unabhängige Prüfung implementiert']);
    }

    // ------------------------------------------------------------------ intern

    private function login(): string
    {
        $now = $this->nowFn !== null ? ($this->nowFn)() : time();
        if ($this->jwt !== null && $now - $this->jwtAt < 600) {
            return $this->jwt;
        }
        $r = $this->raw('POST', '/access/login', json_encode(['username' => $this->username, 'api-key' => $this->apiKey], JSON_THROW_ON_ERROR), null, 'text/plain');
        $token = trim($r['body'], " \t\n\r\0\x0B\"");
        if ($token === '' || preg_match('/^[A-Za-z0-9._-]+$/', $token) !== 1) {
            throw new ProviderException('Anmeldung beim Anbieter lieferte kein Token.');
        }
        $this->jwt = $token;
        $this->jwtAt = $now;
        return $token;
    }

    /** @return array{status:int,body:string} */
    private function call(string $method, string $path, ?string $body, string $accept = 'application/json'): array
    {
        return $this->raw($method, $path, $body, $this->login(), $accept);
    }

    /** @return array{status:int,body:string} */
    private function raw(string $method, string $path, ?string $body, ?string $jwt, string $accept): array
    {
        $headers = ['Accept' => $accept, 'Content-Type' => 'application/json'];
        if ($jwt !== null) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }
        $r = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $headers, $body);
        $s = $r['status'];
        if ($s >= 200 && $s < 300) {
            return $r;
        }
        if ($s === 401) {
            $this->jwt = null;
        }
        // Antworttext wird nicht ausgegeben (könnte Personendaten enthalten).
        throw new ProviderException('Der Anbieter antwortete mit HTTP ' . $s . '.', $s === 429 || $s >= 500, $s);
    }

    /** @return array<string,mixed> */
    private function json(string $body): array
    {
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new ProviderException('Antwort des Anbieters ist kein gültiges JSON.');
        }
        return $j;
    }
}
