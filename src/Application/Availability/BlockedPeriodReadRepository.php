<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;

interface BlockedPeriodReadRepository
{
    /** @return list<BookingPeriod> */
    public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}

