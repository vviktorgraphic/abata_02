<?php

declare(strict_types=1);

namespace App\Application\Booking;

use DateTimeImmutable;
use DateTimeZone;

final readonly class BudapestAdminDashboardClock implements AdminDashboardClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('Europe/Budapest'));
    }
}
