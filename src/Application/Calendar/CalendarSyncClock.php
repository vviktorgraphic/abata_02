<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarSyncClock
{
    public function now(): \DateTimeImmutable;
}
