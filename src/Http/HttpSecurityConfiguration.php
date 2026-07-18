<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class HttpSecurityConfiguration
{
    /** @return array{environment: string, hsts_max_age_seconds: int, trusted_proxy_ips: list<string>} */
    public static function fromValues(string $environment, string|false $hstsMaxAge, string|false $trustedProxyIps): array
    {
        if ($environment === 'production' && ($hstsMaxAge === false || trim($hstsMaxAge) === '')) {
            throw new RuntimeException('HSTS_MAX_AGE_SECONDS is required in production.');
        }
        $hstsMaxAge = $hstsMaxAge === false || trim($hstsMaxAge) === '' ? '0' : $hstsMaxAge;
        if (filter_var($hstsMaxAge, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new RuntimeException('HSTS_MAX_AGE_SECONDS must be a non-negative integer.');
        }
        if ($environment === 'production' && (int) $hstsMaxAge < 1) {
            throw new RuntimeException('HSTS_MAX_AGE_SECONDS must be positive in production.');
        }

        $trustedProxies = [];
        $rawProxies = $trustedProxyIps === false ? '' : trim($trustedProxyIps);
        if ($rawProxies !== '') {
            foreach (explode(',', $rawProxies) as $rawProxy) {
                $proxy = trim($rawProxy);
                if ($proxy === '' || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                    throw new RuntimeException('TRUSTED_PROXY_IPS must contain only comma-separated IP addresses.');
                }
                if (in_array($proxy, $trustedProxies, true)) {
                    throw new RuntimeException('TRUSTED_PROXY_IPS must not contain duplicate IP addresses.');
                }
                $trustedProxies[] = $proxy;
            }
        }

        return [
            'environment' => $environment,
            'hsts_max_age_seconds' => (int) $hstsMaxAge,
            'trusted_proxy_ips' => $trustedProxies,
        ];
    }
}
