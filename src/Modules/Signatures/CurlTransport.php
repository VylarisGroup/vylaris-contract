<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** cURL-Transport mit TLS-Prüfung (nie deaktiviert), Timeouts und Grössenlimit. */
final class CurlTransport implements HttpTransport
{
    public function __construct(private readonly int $timeoutSeconds = 30, private readonly int $maxBytes = 50_000_000)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ProviderException('Nur HTTPS-Verbindungen zu Anbietern sind erlaubt.');
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ProviderException('HTTP-Client konnte nicht gestartet werden.', true);
        }
        $size = 0;
        $out = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$out, &$size): int {
                $size += strlen($chunk);
                if ($size > $this->maxBytes) {
                    return 0; // Abbruch bei zu grosser Antwort
                }
                $out .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new ProviderException('Verbindung zum Anbieter fehlgeschlagen: ' . ($err !== '' ? $err : 'unbekannt'), true);
        }
        return ['status' => $status, 'body' => $out];
    }
}
