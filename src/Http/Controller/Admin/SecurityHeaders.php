<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final class SecurityHeaders
{
    /** @return array<string, string> */
    public static function admin(): array
    {
        return [
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
    }
}
