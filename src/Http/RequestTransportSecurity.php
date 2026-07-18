<?php

declare(strict_types=1);

namespace App\Http;

final readonly class RequestTransportSecurity
{
    /** @param list<string> $trustedProxyIps */
    public function __construct(private array $trustedProxyIps = [])
    {
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        if (strtolower((string) ($server['HTTPS'] ?? '')) === 'on' || (int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $remoteAddress = (string) ($server['REMOTE_ADDR'] ?? '');
        if (!in_array($remoteAddress, $this->trustedProxyIps, true)) {
            return false;
        }
        return strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    }
}
