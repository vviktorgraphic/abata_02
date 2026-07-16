<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;

interface BookingReadRepository
{
    /** @return list<BookingPeriod> */
    public function findBlockingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;
}

