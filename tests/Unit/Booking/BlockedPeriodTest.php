<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\BlockedPeriod;
use App\Domain\Booking\BlockedPeriodInvalid;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BlockedPeriodTest extends TestCase
{
    public function testUsesHalfOpenOverlapRules(): void
    {
        $period = new BlockedPeriod($this->date('2027-01-10'), $this->date('2027-01-15'), 'Maintenance');
        self::assertTrue($period->overlaps($this->date('2027-01-14'), $this->date('2027-01-16')));
        self::assertFalse($period->overlaps($this->date('2027-01-15'), $this->date('2027-01-16')));
        self::assertFalse($period->overlaps($this->date('2027-01-05'), $this->date('2027-01-10')));
    }

    public function testRejectsInvalidIntervalAndOversizedFields(): void
    {
        $this->expectException(BlockedPeriodInvalid::class);
        new BlockedPeriod($this->date('2027-01-10'), $this->date('2027-01-10'), 'Maintenance');
    }

    /** @dataProvider invalidTextProvider */
    public function testRejectsInvalidText(string $reason, ?string $note): void
    {
        $this->expectException(BlockedPeriodInvalid::class);
        new BlockedPeriod($this->date('2027-01-10'), $this->date('2027-01-11'), $reason, $note);
    }

    public static function invalidTextProvider(): array
    {
        return [['', null], [str_repeat('r', 501), null], ['Valid', str_repeat('n', 501)]];
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    }
}
