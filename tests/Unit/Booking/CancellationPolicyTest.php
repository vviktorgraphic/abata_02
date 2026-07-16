<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\CancellationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CancellationPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function boundaries(): iterable
    {
        yield 'eight days before is free' => ['2026-08-02T23:59:00+02:00', '0.00'];
        yield 'exactly seven days before is free' => ['2026-08-03T12:00:00+02:00', '0.00'];
        yield 'six days before is fifty percent' => ['2026-08-04T00:01:00+02:00', '50000.00'];
        yield 'arrival day is fifty percent' => ['2026-08-10T09:00:00+02:00', '50000.00'];
    }

    #[DataProvider('boundaries')]
    public function testCalendarDayBoundaryInBudapest(string $cancelledAt, string $expectedPenalty): void
    {
        $result = (new CancellationPolicy())->calculate(
            '2026-08-10', '100000.00', new \DateTimeImmutable($cancelledAt), 'HUF'
        );

        self::assertSame($expectedPenalty, $result->penaltyAmount);
        self::assertSame('2026-08-03', $result->snapshot['free_cancellation_deadline']);
        self::assertSame('100000.00', $result->snapshot['accommodation_fee']);
        self::assertSame(1, $result->snapshot['version']);
    }

    public function testHalfUpRoundsToWholeHufAndUsesSnapshotFee(): void
    {
        $result = (new CancellationPolicy())->calculate(
            '2026-08-10', '101.00', new \DateTimeImmutable('2026-08-10T00:00:00+02:00')
        );

        self::assertSame('51.00', $result->penaltyAmount);
        self::assertSame(0.5, $result->snapshot['penalty_rate']);
        self::assertSame('HUF', $result->currency);
    }
}
