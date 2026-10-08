<?php

declare(strict_types=1);

namespace App\Application\Booking;

interface AdminMonthlyOccupancyRepository
{
    /** @return array<string, mixed> */
    public function fetch(AdminMonthlyOccupancyQuery $query): array;
}
