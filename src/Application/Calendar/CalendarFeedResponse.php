<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final readonly class CalendarFeedResponse
{
    public function __construct(public int $statusCode, public string $body)
    {
    }
}
