<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        public ?string $streamFile = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Nur relative Pfade oder konfigurierte Basis-URL zulassen (Open-Redirect-Schutz).
        if (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            $location = '/';
        }
        return new self($status, '', ['Location' => $location]);
    }

    /** Dateiauslieferung ausschliesslich aus dem privaten Speicher, nie mit Nutzereingaben im Header. */
    public static function download(string $absolutePath, string $downloadName, string $mime): self
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'dokument';
        return new self(200, '', [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'Content-Length' => (string) filesize($absolutePath),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $absolutePath);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value), true);
        }
        if ($this->streamFile !== null) {
            readfile($this->streamFile);
            return;
        }
        echo $this->body;
    }
}
