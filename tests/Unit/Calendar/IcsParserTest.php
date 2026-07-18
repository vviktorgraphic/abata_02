<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Application\Calendar\IcsParser;
use App\Domain\Calendar\IcsParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    public function testParsesCrLfAllDayEventAsHalfOpenBudapestRange(): void
    {
        $calendar = (new IcsParser())->parse(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT',
            'UID:event-1@example.test', 'DTSTAMP:20260716T120000Z',
            'DTSTART;VALUE=DATE:20261023', 'DTEND;VALUE=DATE:20261026',
            'SEQUENCE:2', 'STATUS:CONFIRMED', 'SUMMARY:Autumn break',
            'DESCRIPTION:First line\\nSecond line', 'LAST-MODIFIED:20260716T140000Z',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]));

        self::assertCount(1, $calendar->events);
        $event = $calendar->events[0];
        self::assertSame('event-1@example.test', $event->uid);
        self::assertTrue($event->allDay);
        self::assertSame('2026-10-23', $event->arrivalDate());
        self::assertSame('2026-10-26', $event->departureDate());
        self::assertSame('Europe/Budapest', $event->startsAt->getTimezone()->getName());
        self::assertSame(2, $event->sequence);
        self::assertSame("First line\nSecond line", $event->description);
        self::assertSame('2026-07-16T14:00:00+02:00', $event->dtstamp?->format('c'));
    }

    public function testAcceptsLfAndUnfoldsAndUnescapesText(): void
    {
        $calendar = (new IcsParser())->parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:u1\nDTSTART;VALUE=DATE:20270101\nDTEND;VALUE=DATE:20270102\nSUMMARY:Long \n text\\, with\\; escapes\\\\ok\nEND:VEVENT\nEND:VCALENDAR");

        self::assertSame('Long text, with; escapes\\ok', $calendar->events[0]->summary);
    }

    public function testConvertsUtcAndTzidDateTimesToBudapest(): void
    {
        $calendar = (new IcsParser())->parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:timed\nDTSTART:20261025T003000Z\nDTEND;TZID=Europe/London:20261025T023000\nEND:VEVENT\nEND:VCALENDAR");

        self::assertFalse($calendar->events[0]->allDay);
        self::assertSame('2026-10-25T02:30:00+02:00', $calendar->events[0]->startsAt->format('c'));
        self::assertSame('Europe/Budapest', $calendar->events[0]->endsAt->getTimezone()->getName());
    }

    #[DataProvider('invalidFeeds')]
    public function testRejectsMalformedComponentsDatesAndDurations(string $feed): void
    {
        $this->expectException(IcsParseException::class);
        (new IcsParser())->parse($feed);
    }

    public static function invalidFeeds(): array
    {
        return [
            'no calendar' => ['BEGIN:VEVENT\nEND:VEVENT'],
            'incomplete event' => ["BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:u\nEND:VCALENDAR"],
            'missing uid' => [self::feed('DTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260102')],
            'invalid date' => [self::feed('UID:u\nDTSTART;VALUE=DATE:20260230\nDTEND;VALUE=DATE:20260302')],
            'zero duration' => [self::feed('UID:u\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260101')],
            'mixed types' => [self::feed('UID:u\nDTSTART;VALUE=DATE:20260101\nDTEND:20260102T120000')],
            'bad sequence' => [self::feed('UID:u\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260102\nSEQUENCE:-1')],
            'duplicate uid' => [self::feed('UID:u\nUID:v\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260102')],
        ];
    }

    public function testEnforcesFeedEventAndLineLimits(): void
    {
        foreach ([
            [new IcsParser(10), "BEGIN:VCALENDAR\nEND:VCALENDAR"],
            [new IcsParser(1000, 1), "BEGIN:VCALENDAR\n" . self::event('a') . "\n" . self::event('b') . "\nEND:VCALENDAR"],
            [new IcsParser(1000, 10, 8), "BEGIN:VCALENDAR\nEND:VCALENDAR"],
        ] as [$parser, $feed]) {
            try {
                $parser->parse($feed);
                self::fail('A configured resource limit should reject the feed.');
            } catch (IcsParseException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function feed(string $body): string
    {
        return "BEGIN:VCALENDAR\nBEGIN:VEVENT\n{$body}\nEND:VEVENT\nEND:VCALENDAR";
    }

    private static function event(string $uid): string
    {
        return "BEGIN:VEVENT\nUID:{$uid}\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260102\nEND:VEVENT";
    }
}
