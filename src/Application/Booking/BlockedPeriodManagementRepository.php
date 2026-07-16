<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BlockedPeriod;

interface BlockedPeriodManagementRepository
{
    public function create(BlockedPeriod $period, int $adminId): BlockedPeriodCreation;

    public function remove(int $id, int $adminId): void;

    /** @return list<array<string, mixed>> */
    public function active(): array;
}
