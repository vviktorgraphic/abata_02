<?php

declare(strict_types=1);

namespace App\Application\Booking;

use DateTimeImmutable;

interface AdminDashboardClock
{
    public function today(): DateTimeImmutable;
}
