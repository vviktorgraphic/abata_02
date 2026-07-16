<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AvailabilityService
{
    public function __construct(private DateTimeImmutable $today)
    {
    }

    public static function forBudapestToday(): self
    {
        return new self(new DateTimeImmutable('today', new \DateTimeZone('Europe/Budapest')));
    }

    public function assertArrivalIsNotPast(BookingPeriod $period): void
    {
        if ($period->arrival < $this->today) {
            throw new InvalidArgumentException('Arrival date cannot be in the past.');
        }
    }

    public function overlaps(BookingPeriod $left, BookingPeriod $right): bool
    {
        return $left->arrival < $right->departure
            && $left->departure > $right->arrival;
    }

    /** @param iterable<BookingPeriod> $occupiedPeriods */
    public function isAvailable(BookingPeriod $requestedPeriod, iterable $occupiedPeriods): bool
    {
        foreach ($occupiedPeriods as $occupiedPeriod) {
            if ($this->overlaps($requestedPeriod, $occupiedPeriod)) {
                return false;
            }
        }

        return true;
    }
}

