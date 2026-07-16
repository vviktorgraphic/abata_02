<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Booking\BookingPeriod;
use DateInterval;
use DateTimeImmutable;

final readonly class AvailabilityCalendarService
{
    public function __construct(private DateTimeImmutable $today)
    {
    }

    /**
     * @param iterable<BookingPeriod> $bookings Only blocking-status bookings.
     * @param iterable<BookingPeriod> $blockedPeriods
     * @return list<DayAvailability>
     */
    public function build(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        iterable $bookings,
        iterable $blockedPeriods,
    ): array {
        $bookings = is_array($bookings) ? $bookings : iterator_to_array($bookings);
        $blockedPeriods = is_array($blockedPeriods) ? $blockedPeriods : iterator_to_array($blockedPeriods);
        $days = [];

        for ($date = $from; $date < $to; $date = $date->add(new DateInterval('P1D'))) {
            $days[] = $this->forDate($date, $bookings, $blockedPeriods);
        }

        return $days;
    }

    /** @param list<BookingPeriod> $bookings @param list<BookingPeriod> $blockedPeriods */
    private function forDate(DateTimeImmutable $date, array $bookings, array $blockedPeriods): DayAvailability
    {
        if ($date < $this->today) {
            return new DayAvailability($date, AvailabilityStatus::Past, false, false);
        }

        foreach ($blockedPeriods as $period) {
            if ($date >= $period->arrival && $date < $period->departure) {
                return new DayAvailability($date, AvailabilityStatus::Blocked, false, false);
            }
        }

        $hasArrival = false;
        $hasDeparture = false;
        $isOccupiedNight = false;

        foreach ($bookings as $period) {
            $hasArrival = $hasArrival || $date == $period->arrival;
            $hasDeparture = $hasDeparture || $date == $period->departure;
            $isOccupiedNight = $isOccupiedNight || ($date >= $period->arrival && $date < $period->departure);
        }

        if ($hasArrival && $hasDeparture) {
            return new DayAvailability($date, AvailabilityStatus::Turnover, false, true);
        }

        if ($hasArrival) {
            return new DayAvailability($date, AvailabilityStatus::ArrivalOnly, false, true);
        }

        if ($hasDeparture) {
            return new DayAvailability($date, AvailabilityStatus::DepartureOnly, true, true);
        }

        if ($isOccupiedNight) {
            return new DayAvailability($date, AvailabilityStatus::Occupied, false, false);
        }

        return new DayAvailability($date, AvailabilityStatus::Available, true, true);
    }
}

