<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\EmailOutboxRetryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailOutboxRetryPolicyTest extends TestCase
{
    public function testApprovedValuesAreCentralized(): void
    {
        $policy = new EmailOutboxRetryPolicy();

        self::assertSame(5, $policy->maxAttempts());
        self::assertSame(900, $policy->staleProcessingSeconds());
        self::assertSame(300, $policy->retryDelaySeconds(1));
        self::assertSame(900, $policy->retryDelaySeconds(2));
        self::assertSame(3600, $policy->retryDelaySeconds(3));
        self::assertSame(21600, $policy->retryDelaySeconds(4));
        self::assertNull($policy->retryDelaySeconds(5));
        foreach ([0, 1, 2, 3, 4] as $attempts) {
            self::assertTrue($policy->canAttempt($attempts));
        }
        self::assertFalse($policy->canAttempt(5));
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function dueBoundaries(): iterable
    {
        yield 'attempt 1 at 4m59s' => [1, '12:04:59', false];
        yield 'attempt 1 at 5m' => [1, '12:05:00', true];
        yield 'attempt 2 at 14m59s' => [2, '12:14:59', false];
        yield 'attempt 2 at 15m' => [2, '12:15:00', true];
        yield 'attempt 3 at 59m59s' => [3, '12:59:59', false];
        yield 'attempt 3 at 1h' => [3, '13:00:00', true];
        yield 'attempt 4 at 5h59m59s' => [4, '17:59:59', false];
        yield 'attempt 4 at 6h' => [4, '18:00:00', true];
        yield 'attempt 5 never retries' => [5, '23:59:59', false];
    }

    #[DataProvider('dueBoundaries')]
    public function testRetryBoundary(int $attempts, string $clock, bool $expected): void
    {
        $zone = new DateTimeZone('Europe/Budapest');
        $updated = new DateTimeImmutable('2026-10-12 12:00:00', $zone);
        $now = new DateTimeImmutable('2026-10-12 ' . $clock, $zone);

        self::assertSame($expected, (new EmailOutboxRetryPolicy())->isDue('failed', $attempts, $updated, $now));
    }

    public function testPendingZeroIsImmediatelyDueAndOtherPendingRowsAreNot(): void
    {
        $now = new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('Europe/Budapest'));
        $policy = new EmailOutboxRetryPolicy();

        self::assertTrue($policy->isDue('pending', 0, $now, $now));
        self::assertFalse($policy->isDue('pending', 1, $now, $now));
        self::assertFalse($policy->isDue('sent', 0, $now, $now));
    }

    public function testStaleBoundary(): void
    {
        $zone = new DateTimeZone('Europe/Budapest');
        $updated = new DateTimeImmutable('2026-10-12 12:00:00', $zone);
        $policy = new EmailOutboxRetryPolicy();

        self::assertFalse($policy->isStale($updated, new DateTimeImmutable('2026-10-12 12:14:59', $zone)));
        self::assertTrue($policy->isStale($updated, new DateTimeImmutable('2026-10-12 12:15:00', $zone)));
    }
}
