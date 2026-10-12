<?php

declare(strict_types=1);

namespace App\Application\Booking;

use DateTimeImmutable;

interface AdminDashboardRepository
{
    public function fetch(DateTimeImmutable $today): AdminDashboardOverview;
}
