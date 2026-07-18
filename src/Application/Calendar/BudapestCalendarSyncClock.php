<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final class BudapestCalendarSyncClock implements CalendarSyncClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest'));
    }
}
