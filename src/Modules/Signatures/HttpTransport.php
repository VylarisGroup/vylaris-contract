<?php

declare(strict_types=1);

namespace App\Modules\Signatures;

/** Minimale HTTP-Abstraktion, damit Anbieteradapter ohne Netzwerk testbar sind. */
interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     * @throws ProviderException bei Netzwerkfehlern (retryable)
     */
    public function request(string $method, string $url, array $headers, ?string $body): array;
}
