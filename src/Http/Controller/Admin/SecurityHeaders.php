<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final class SecurityHeaders
{
    /** @return array<string, string> */
    public static function common(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
    }

    /** @return array<string, string> */
    public static function admin(): array
    {
        return array_merge(self::common(), [
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @return array<string, string> */
    public static function transport(string $environment, bool $isHttps, int $hstsMaxAgeSeconds): array
    {
        if ($environment !== 'production' || !$isHttps) {
            return [];
        }
        if ($hstsMaxAgeSeconds < 1) {
            throw new \InvalidArgumentException('Production HSTS max-age must be positive.');
        }
        return ['Strict-Transport-Security' => 'max-age=' . $hstsMaxAgeSeconds];
    }
}
