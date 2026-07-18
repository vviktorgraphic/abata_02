<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Application\Calendar\IcalExporter;
use App\Domain\Calendar\IcalExportEvent;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IcalExporterTest extends TestCase
{
    public function testExportsRfc5545AllDayHalfOpenEventWithRequiredFields(): void
    {
        $event = new IcalExportEvent(
            'booking-public-id@example.invalid',
            $this->budapestDate('2027-07-10'),
            $this->budapestDate('2027-07-13'),
            new DateTimeImmutable('2027-06-01 13:14:15', new DateTimeZone('Europe/Budapest')),
        );

        $ics = (new IcalExporter())->export(
            [$event],
            new DateTimeImmutable('2027-06-02 13:14:15', new DateTimeZone('Europe/Budapest')),
        );

        self::assertStringContainsString("UID:booking-public-id@example.invalid\r\n", $ics);
        self::assertStringContainsString("DTSTAMP:20270602T111415Z\r\n", $ics);
        self::assertStringContainsString("DTSTART;VALUE=DATE:20270710\r\n", $ics);
        self::assertStringContainsString("DTEND;VALUE=DATE:20270713\r\n", $ics);
        self::assertStringContainsString("SUMMARY:Foglalt\r\n", $ics);
        self::assertStringContainsString("DESCRIPTION:A szállás ezen az időszakon nem foglalható.\r\n", $ics);
        self::assertStringContainsString("LAST-MODIFIED:20270601T111415Z\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $ics);
    }

    public function testUsesTheStableUidSuppliedByPersistence(): void
    {
        $event = new IcalExportEvent('stable-uid@example.invalid', $this->budapestDate('2027-01-01'), $this->budapestDate('2027-01-02'), new DateTimeImmutable('now'));
        $exporter = new IcalExporter();
        $at = new DateTimeImmutable('2027-01-01 00:00:00 UTC');

        self::assertSame($exporter->export([$event], $at), $exporter->export([$event], $at));
        self::assertSame(1, substr_count($exporter->export([$event], $at), 'UID:stable-uid@example.invalid'));
    }

    public function testEscapesRfc5545TextCharacters(): void
    {
        $event = new IcalExportEvent('stable,part;with\\slash@example.invalid', $this->budapestDate('2027-01-01'), $this->budapestDate('2027-01-02'), new DateTimeImmutable('now'));

        $ics = (new IcalExporter())->export([$event], new DateTimeImmutable('now'));

        self::assertStringContainsString('UID:stable\\,part\\;with\\\\slash@example.invalid', $ics);
    }

    public function testFeedContainsOnlyGenericPiiFreePresentation(): void
    {
        $event = new IcalExportEvent('opaque@example.invalid', $this->budapestDate('2027-01-01'), $this->budapestDate('2027-01-02'), new DateTimeImmutable('now'));
        $ics = (new IcalExporter())->export([$event], new DateTimeImmutable('now'));

        self::assertStringNotContainsString('guest', strtolower($ics));
        self::assertStringNotContainsString('email', strtolower($ics));
        self::assertStringNotContainsString('price', strtolower($ics));
        self::assertStringNotContainsString('note', strtolower($ics));
    }

    public function testFoldsEveryPhysicalLineAtSeventyFiveOctets(): void
    {
        $uid = str_repeat('á', 50) . '@example.invalid';
        $event = new IcalExportEvent($uid, $this->budapestDate('2027-01-01'), $this->budapestDate('2027-01-02'), new DateTimeImmutable('now'));
        $ics = (new IcalExporter())->export([$event], new DateTimeImmutable('now'));

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        self::assertStringContainsString("\r\n ", $ics);
    }

    /** @dataProvider invalidPeriodProvider */
    public function testRejectsInvalidOrNonBudapestCalendarPeriods(DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IcalExportEvent('uid@example.invalid', $start, $end, new DateTimeImmutable('now'));
    }

    public static function invalidPeriodProvider(): array
    {
        $budapest = new DateTimeZone('Europe/Budapest');
        $utc = new DateTimeZone('UTC');

        return [
            'empty' => [new DateTimeImmutable('2027-01-01 00:00:00', $budapest), new DateTimeImmutable('2027-01-01 00:00:00', $budapest)],
            'timed' => [new DateTimeImmutable('2027-01-01 12:00:00', $budapest), new DateTimeImmutable('2027-01-02 00:00:00', $budapest)],
            'wrong timezone' => [new DateTimeImmutable('2027-01-01 00:00:00', $utc), new DateTimeImmutable('2027-01-02 00:00:00', $utc)],
        ];
    }

    public function testRejectsControlCharactersInUid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IcalExportEvent("unsafe\r\nUID:injected", $this->budapestDate('2027-01-01'), $this->budapestDate('2027-01-02'), new DateTimeImmutable('now'));
    }

    private function budapestDate(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    }
}
