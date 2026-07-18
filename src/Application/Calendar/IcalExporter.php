<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Calendar\IcalExportEvent;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IcalExporter
{
    private const CRLF = "\r\n";

    /**
     * @param iterable<IcalExportEvent> $events
     */
    public function export(iterable $events, DateTimeImmutable $generatedAt): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//Foglalasi Rendszer//iCal 1.0//HU',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        $dtstamp = $this->utcTimestamp($generatedAt);
        foreach ($events as $event) {
            if (!$event instanceof IcalExportEvent) {
                throw new InvalidArgumentException('Every exported item must be an IcalExportEvent.');
            }

            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:' . $this->escapeText($event->uid),
                'DTSTAMP:' . $dtstamp,
                'DTSTART;VALUE=DATE:' . $event->startDate->format('Ymd'),
                'DTEND;VALUE=DATE:' . $event->endDate->format('Ymd'),
                'SUMMARY:Foglalt',
                'DESCRIPTION:A szállás ezen az időszakon nem foglalható.',
                'LAST-MODIFIED:' . $this->utcTimestamp($event->lastModified),
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map($this->foldLine(...), $lines)) . self::CRLF;
    }

    private function utcTimestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function escapeText(string $value): string
    {
        return str_replace(
            ["\\", "\r\n", "\r", "\n", ';', ','],
            ["\\\\", "\\n", "\\n", "\\n", '\\;', '\\,'],
            $value,
        );
    }

    /** Fold content lines at 75 octets without splitting a UTF-8 character. */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $remaining = $line;
        $limit = 75;
        while (strlen($remaining) > $limit) {
            $length = $limit;
            while ($length > 0 && (ord($remaining[$length]) & 0xC0) === 0x80) {
                --$length;
            }
            $parts[] = substr($remaining, 0, $length);
            $remaining = substr($remaining, $length);
            $limit = 74; // The continuation space counts toward the 75-octet limit.
        }
        $parts[] = $remaining;

        return implode(self::CRLF . ' ', $parts);
    }
}
