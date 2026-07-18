<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Calendar\IcsCalendar;
use App\Domain\Calendar\IcsEvent;
use App\Domain\Calendar\IcsParseException;
use DateTimeImmutable;
use DateTimeZone;

final class IcsParser
{
    private const BUDAPEST = 'Europe/Budapest';

    public function __construct(
        private readonly int $maxBytes = 2_000_000,
        private readonly int $maxEvents = 5_000,
        private readonly int $maxUnfoldedLineBytes = 32_768,
    ) {
        if ($maxBytes < 1 || $maxEvents < 1 || $maxUnfoldedLineBytes < 1) {
            throw new \InvalidArgumentException('ICS parser limits must be positive.');
        }
    }

    public function parse(string $contents): IcsCalendar
    {
        if (strlen($contents) > $this->maxBytes) {
            throw new IcsParseException('The iCalendar feed exceeds the size limit.');
        }
        if (str_contains($contents, "\0")) {
            throw new IcsParseException('The iCalendar feed contains invalid binary data.');
        }

        $lines = $this->unfold($contents);
        if ($lines === [] || strtoupper($lines[0]) !== 'BEGIN:VCALENDAR' || strtoupper(end($lines)) !== 'END:VCALENDAR') {
            throw new IcsParseException('A complete VCALENDAR component is required.');
        }

        $events = [];
        $current = null;
        foreach (array_slice($lines, 1, -1) as $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                if ($current !== null) {
                    throw new IcsParseException('Nested VEVENT components are invalid.');
                }
                $current = [];
                continue;
            }
            if ($upper === 'END:VEVENT') {
                if ($current === null) {
                    throw new IcsParseException('Unexpected VEVENT end marker.');
                }
                $events[] = $this->createEvent($current);
                $current = null;
                if (count($events) > $this->maxEvents) {
                    throw new IcsParseException('The iCalendar feed contains too many events.');
                }
                continue;
            }
            if ($current !== null) {
                [$name, $params, $value] = $this->property($line);
                $current[$name][] = ['params' => $params, 'value' => $value];
            }
        }
        if ($current !== null) {
            throw new IcsParseException('The VEVENT component is incomplete.');
        }

        return new IcsCalendar($events);
    }

    /** @return list<string> */
    private function unfold(string $contents): array
    {
        $physical = preg_split('/\r\n|\n|\r/', $contents);
        if ($physical === false) {
            throw new IcsParseException('The iCalendar feed cannot be read.');
        }
        while ($physical !== [] && end($physical) === '') {
            array_pop($physical);
        }
        $lines = [];
        foreach ($physical as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($lines === []) {
                    throw new IcsParseException('A folded line must follow a content line.');
                }
                $lines[array_key_last($lines)] .= substr($line, 1);
            } else {
                $lines[] = $line;
            }
            if (strlen($lines[array_key_last($lines)]) > $this->maxUnfoldedLineBytes) {
                throw new IcsParseException('An unfolded iCalendar line exceeds the size limit.');
            }
        }
        return $lines;
    }

    /** @return array{string, array<string,string>, string} */
    private function property(string $line): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            throw new IcsParseException('An iCalendar property has no value separator.');
        }
        $parts = explode(';', substr($line, 0, $colon));
        $name = strtoupper((string) array_shift($parts));
        if ($name === '' || preg_match('/^[A-Z0-9-]+$/', $name) !== 1) {
            throw new IcsParseException('An iCalendar property name is invalid.');
        }
        $params = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                throw new IcsParseException('An iCalendar property parameter is invalid.');
            }
            [$key, $value] = explode('=', $part, 2);
            $params[strtoupper($key)] = trim($value, '"');
        }
        return [$name, $params, substr($line, $colon + 1)];
    }

    /** @param array<string, list<array{params: array<string,string>, value: string}>> $properties */
    private function createEvent(array $properties): IcsEvent
    {
        $uid = trim($this->one($properties, 'UID')['value'] ?? '');
        if ($uid === '') {
            throw new IcsParseException('VEVENT UID is required.');
        }
        $startProperty = $this->one($properties, 'DTSTART');
        $endProperty = $this->one($properties, 'DTEND');
        if ($startProperty === null || $endProperty === null) {
            throw new IcsParseException('VEVENT DTSTART and DTEND are required.');
        }
        $allDay = strtoupper($startProperty['params']['VALUE'] ?? '') === 'DATE';
        if ($allDay !== (strtoupper($endProperty['params']['VALUE'] ?? '') === 'DATE')) {
            throw new IcsParseException('VEVENT DTSTART and DTEND must use the same value type.');
        }
        $start = $this->dateTime($startProperty, $allDay, 'DTSTART');
        $end = $this->dateTime($endProperty, $allDay, 'DTEND');
        if ($end <= $start) {
            throw new IcsParseException('VEVENT DTEND must be later than DTSTART.');
        }

        $sequenceValue = $this->one($properties, 'SEQUENCE')['value'] ?? '0';
        if (preg_match('/^\d+$/', $sequenceValue) !== 1) {
            throw new IcsParseException('VEVENT SEQUENCE must be a non-negative integer.');
        }

        return new IcsEvent(
            $this->text($uid),
            $start,
            $end,
            $allDay,
            $this->optionalTimestamp($properties, 'DTSTAMP'),
            (int) $sequenceValue,
            isset($properties['STATUS']) ? strtoupper(trim($this->one($properties, 'STATUS')['value'])) : null,
            $this->text($this->one($properties, 'SUMMARY')['value'] ?? ''),
            $this->text($this->one($properties, 'DESCRIPTION')['value'] ?? ''),
            $this->optionalTimestamp($properties, 'LAST-MODIFIED'),
        );
    }

    /** @param array<string, list<array{params: array<string,string>, value: string}>> $properties
     *  @return array{params: array<string,string>, value: string}|null */
    private function one(array $properties, string $name): ?array
    {
        if (!isset($properties[$name])) {
            return null;
        }
        if (count($properties[$name]) !== 1) {
            throw new IcsParseException("VEVENT {$name} must occur at most once.");
        }
        return $properties[$name][0];
    }

    /** @param array{params: array<string,string>, value: string} $property */
    private function dateTime(array $property, bool $allDay, string $name): DateTimeImmutable
    {
        $value = trim($property['value']);
        if ($allDay) {
            if (preg_match('/^\d{8}$/', $value) !== 1) {
                throw new IcsParseException("VEVENT {$name} contains an invalid DATE value.");
            }
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone(self::BUDAPEST));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Ymd') !== $value) {
                throw new IcsParseException("VEVENT {$name} contains an invalid calendar date.");
            }
            return $date;
        }

        $tzid = $property['params']['TZID'] ?? null;
        try {
            $zone = $tzid !== null ? new DateTimeZone($tzid) : new DateTimeZone(self::BUDAPEST);
        } catch (\Throwable) {
            throw new IcsParseException("VEVENT {$name} contains an invalid TZID.");
        }
        $utc = str_ends_with($value, 'Z');
        $format = $utc ? '!Ymd\THis\Z' : '!Ymd\THis';
        if (preg_match($utc ? '/^\d{8}T\d{6}Z$/' : '/^\d{8}T\d{6}$/', $value) !== 1) {
            throw new IcsParseException("VEVENT {$name} contains an invalid DATE-TIME value.");
        }
        $date = DateTimeImmutable::createFromFormat($format, $value, $utc ? new DateTimeZone('UTC') : $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new IcsParseException("VEVENT {$name} contains an invalid date-time.");
        }
        return $date->setTimezone(new DateTimeZone(self::BUDAPEST));
    }

    /** @param array<string, list<array{params: array<string,string>, value: string}>> $properties */
    private function optionalTimestamp(array $properties, string $name): ?DateTimeImmutable
    {
        $property = $this->one($properties, $name);
        return $property === null ? null : $this->dateTime($property, false, $name);
    }

    private function text(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([nN,;\\\\])/', static function (array $match): string {
            return match ($match[1]) {
                'n', 'N' => "\n",
                default => $match[1],
            };
        }, $value);
    }
}
