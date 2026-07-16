<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    private AvailabilityService $service;

    protected function setUp(): void
    {
        $this->service = new AvailabilityService($this->date('2026-07-16'));
    }

    public function testConsecutiveBookingsDoNotOverlap(): void
    {
        $first = $this->period('2026-08-01', '2026-08-05');
        $second = $this->period('2026-08-05', '2026-08-10');

        self::assertFalse($this->service->overlaps($first, $second));
        self::assertTrue($this->service->isAvailable($second, [$first]));
    }

    public function testBookingsStartingOnTheSameDayOverlap(): void
    {
        self::assertTrue($this->service->overlaps(
            $this->period('2026-08-01', '2026-08-05'),
            $this->period('2026-08-01', '2026-08-03'),
        ));
    }

    public function testEnclosedPeriodOverlaps(): void
    {
        self::assertTrue($this->service->overlaps(
            $this->period('2026-08-01', '2026-08-10'),
            $this->period('2026-08-03', '2026-08-05'),
        ));
    }

    public function testPastArrivalCanBeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assertArrivalIsNotPast($this->period('2026-07-15', '2026-07-17'));
    }

    /** @dataProvider invalidPeriodProvider */
    public function testDepartureMustBeLaterThanArrival(string $arrival, string $departure): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->period($arrival, $departure);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPeriodProvider(): iterable
    {
        yield 'same date' => ['2026-08-01', '2026-08-01'];
        yield 'earlier departure' => ['2026-08-02', '2026-08-01'];
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

