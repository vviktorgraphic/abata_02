<?php

declare(strict_types=1);

namespace App\Infrastructure\Calendar;

use App\Application\Calendar\CalendarHostResolver;

final class NativeCalendarHostResolver implements CalendarHostResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }
}
