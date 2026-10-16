<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Unveränderliche Sicht auf die HTTP-Anfrage. Vertraut Proxy-Headern nur,
 * wenn TRUSTED_PROXIES konfiguriert ist (hier: explizite Liste von IPs).
 */
final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post
     *  @param array<string,mixed> $server @param array<string,mixed> $files
     *  @param array<string,string> $cookies @param list<string> $trustedProxies */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files,
        public readonly array $cookies,
        private readonly array $trustedProxies = [],
        public readonly string $correlationId = '',
    ) {
    }

    /** @param list<string> $trustedProxies */
    public static function fromGlobals(array $trustedProxies = []): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
            $trustedProxies,
            bin2hex(random_bytes(8)),
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_string($v) && preg_match('/^-?\d{1,18}$/', $v) === 1 ? (int) $v : $default;
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->trustedProxies !== [] && in_array($remote, $this->trustedProxies, true)) {
            $fwd = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        return $remote;
    }

    public function isHttps(): bool
    {
        $https = (string) ($this->server['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return $this->trustedProxies !== []
            && in_array($remote, $this->trustedProxies, true)
            && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
