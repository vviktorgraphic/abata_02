<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BookingPeriod
{
    public function __construct(
        public DateTimeImmutable $arrival,
        public DateTimeImmutable $departure,
    ) {
        if ($departure <= $arrival) {
            throw new InvalidArgumentException('Departure must be later than arrival.');
        }

        if ($arrival->format('H:i:s') !== '00:00:00' || $departure->format('H:i:s') !== '00:00:00') {
            throw new InvalidArgumentException('Booking periods must use whole calendar dates.');
        }
    }
}

