<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Availability\AvailabilityCalendarService;
use App\Domain\Availability\AvailabilityStatus;
use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AvailabilityCalendarServiceTest extends TestCase
{
    public function testComposesDailyStatusesAndSelectability(): void
    {
        $days = $this->service()->build(
            $this->date('2026-08-01'),
            $this->date('2026-08-12'),
            [
                $this->period('2026-08-02', '2026-08-05'),
                $this->period('2026-08-05', '2026-08-08'),
            ],
            [$this->period('2026-08-09', '2026-08-11')],
        );
        $byDate = [];
        foreach ($days as $day) {
            $byDate[$day->date->format('Y-m-d')] = $day;
        }

        self::assertSame(AvailabilityStatus::Available, $byDate['2026-08-01']->status);
        self::assertTrue($byDate['2026-08-01']->selectableAsArrival);
        self::assertSame(AvailabilityStatus::ArrivalOnly, $byDate['2026-08-02']->status);
        self::assertTrue($byDate['2026-08-02']->selectableAsDeparture);
        self::assertSame(AvailabilityStatus::Occupied, $byDate['2026-08-03']->status);
        self::assertSame(AvailabilityStatus::Turnover, $byDate['2026-08-05']->status);
        self::assertSame(AvailabilityStatus::DepartureOnly, $byDate['2026-08-08']->status);
        self::assertTrue($byDate['2026-08-08']->selectableAsArrival);
        self::assertSame(AvailabilityStatus::Blocked, $byDate['2026-08-09']->status);
        self::assertFalse($byDate['2026-08-09']->selectableAsArrival);
    }

    public function testPastDayIsNotSelectable(): void
    {
        $day = $this->service()->build($this->date('2026-07-31'), $this->date('2026-08-01'), [], [])[0];
        self::assertSame(AvailabilityStatus::Past, $day->status);
        self::assertFalse($day->selectableAsArrival);
        self::assertFalse($day->selectableAsDeparture);
    }

    public function testNonBlockingStatusesAreExcludedBeforeCalendarComposition(): void
    {
        $pendingAndCancelledPeriods = [];
        $day = $this->service()->build(
            $this->date('2026-08-01'),
            $this->date('2026-08-02'),
            $pendingAndCancelledPeriods,
            [],
        )[0];
        self::assertSame(AvailabilityStatus::Available, $day->status);
    }

    private function service(): AvailabilityCalendarService
    {
        return new AvailabilityCalendarService($this->date('2026-08-01'));
    }

    private function period(string $arrival, string $departure): BookingPeriod
    {
        return new BookingPeriod($this->date($arrival), $this->date($departure));
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

