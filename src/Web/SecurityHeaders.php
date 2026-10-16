<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Response;

final class SecurityHeaders
{
    public static function apply(Response $res, bool $https): Response
    {
        // Kein Inline-Skript, kein Inline-Style, keine fremden Quellen. Keine Seite darf eingebettet werden.
        $res->headers += [
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($https) {
            $res->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        if (!isset($res->headers['Cache-Control'])) {
            $res->headers['Cache-Control'] = 'no-store';
        }
        return $res;
    }
}
