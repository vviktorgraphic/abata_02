<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarFeedHttpClient
{
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse;
}
