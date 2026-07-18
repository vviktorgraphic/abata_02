<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final readonly class SecureCalendarFeedFetcher
{
    public function __construct(
        private CalendarFeedHttpClient $client,
        private CalendarHostResolver $resolver,
        private int $timeoutSeconds = 10,
        private int $maxBytes = 2_000_000,
    ) {
        if ($timeoutSeconds < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('Calendar feed limits must be positive.');
        }
    }

    public function fetch(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new CalendarFeedFetchException('Calendar feed URL must be an HTTPS URL without credentials.');
        }
        $host = rtrim(strtolower((string) $parts['host']), '.');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new CalendarFeedFetchException('Calendar feed host is not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new CalendarFeedFetchException('Calendar feed host cannot be resolved.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new CalendarFeedFetchException('Calendar feed host resolves to a private or reserved address.');
            }
        }

        // Pin the request to an address we validated. This closes the DNS-rebinding gap.
        $response = $this->client->get($url, $ips[0], $this->timeoutSeconds, $this->maxBytes);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new CalendarFeedFetchException('Calendar feed returned HTTP status ' . $response->statusCode . '.');
        }
        if (strlen($response->body) > $this->maxBytes) {
            throw new CalendarFeedFetchException('Calendar feed exceeds the response size limit.');
        }
        return $response->body;
    }

}
