<?php

declare(strict_types=1);

namespace App\Infrastructure\Calendar;

use App\Application\Calendar\CalendarFeedFetchException;
use App\Application\Calendar\CalendarFeedHttpClient;
use App\Application\Calendar\CalendarFeedResponse;

final class CurlCalendarFeedHttpClient implements CalendarFeedHttpClient
{
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse
    {
        if (!function_exists('curl_init')) {
            throw new CalendarFeedFetchException('The cURL extension is required for calendar imports.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new CalendarFeedFetchException('Calendar feed request cannot be initialized.');
        }
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $resolvedIp)],
            CURLOPT_USERAGENT => 'BataBookingCalendarSync/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false) {
                throw new CalendarFeedFetchException('Calendar feed request failed: ' . curl_error($curl));
            }
            return new CalendarFeedResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);
        } finally {
            curl_close($curl);
        }
    }
}
