<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BookingLifecycleConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['BOOKING_LIFECYCLE_ENABLED', 'BOOKING_LIFECYCLE_START_DATE'] as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testDisabledIsTheSafeDefaultAndAllowsEmptyStartDate(): void
    {
        $configuration = $this->configuration();

        self::assertFalse($configuration['enabled']);
        self::assertNull($configuration['start_date']);
    }

    public function testEnabledRequiresValidBudapestCalendarDate(): void
    {
        putenv('BOOKING_LIFECYCLE_ENABLED=true');
        putenv('BOOKING_LIFECYCLE_START_DATE=2026-10-09');

        self::assertSame(['enabled' => true, 'start_date' => '2026-10-09'], $this->configuration());
    }

    public function testBooleanParsingIsStrict(): void
    {
        putenv('BOOKING_LIFECYCLE_ENABLED=1');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly true or false');

        $this->configuration();
    }

    public function testEnabledRejectsMissingStartDate(): void
    {
        putenv('BOOKING_LIFECYCLE_ENABLED=true');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOKING_LIFECYCLE_START_DATE is required');

        $this->configuration();
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        putenv('BOOKING_LIFECYCLE_ENABLED=true');
        putenv('BOOKING_LIFECYCLE_START_DATE=2026-02-30');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid YYYY-MM-DD');

        $this->configuration();
    }

    /** @return array{enabled:bool,start_date:?string} */
    private function configuration(): array
    {
        return require dirname(__DIR__, 3) . '/config/booking-lifecycle.php';
    }
}
