<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Booking\AdminMonthlyOccupancyQuery;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminMonthlyOccupancyQueryTest extends TestCase
{
    public function test_defaults_to_the_current_budapest_month_and_builds_navigation(): void
    {
        $query = new AdminMonthlyOccupancyQuery(null, new DateTimeImmutable('2026-10-31 23:30:00+00:00'));

        self::assertSame('2026-11', $query->month);
        self::assertSame('2026-10', $query->previousMonth);
        self::assertSame('2026-12', $query->nextMonth);
        self::assertSame('2026-11-01', $query->today);
        self::assertSame('2026. november', $query->label);
        self::assertCount(30, $query->dayDates());
    }

    public function test_navigation_crosses_year_boundaries(): void
    {
        $query = new AdminMonthlyOccupancyQuery('2026-01', new DateTimeImmutable('2026-06-01'));

        self::assertSame('2025-12', $query->previousMonth);
        self::assertSame('2026-02', $query->nextMonth);
        self::assertCount(31, $query->dayDates());

        $december = new AdminMonthlyOccupancyQuery('2026-12', new DateTimeImmutable('2026-06-01'));
        self::assertSame('2027-01', $december->nextMonth);
    }

    #[DataProvider('invalidMonths')]
    public function test_rejects_non_canonical_month_values(mixed $month): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AdminMonthlyOccupancyQuery($month, new DateTimeImmutable('2026-10-08'));
    }

    public static function invalidMonths(): iterable
    {
        yield 'empty' => [''];
        yield 'missing zero' => ['2026-1'];
        yield 'day included' => ['2026-10-01'];
        yield 'invalid month' => ['2026-13'];
        yield 'array pollution' => [['2026-10']];
    }
}
