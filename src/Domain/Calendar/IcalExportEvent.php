<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

/** A deliberately PII-free calendar projection. */
final readonly class IcalExportEvent
{
    public function __construct(
        public string $uid,
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public DateTimeImmutable $lastModified,
    ) {
        if ($uid === '' || preg_match('/[\x00-\x1F\x7F]/', $uid) === 1) {
            throw new InvalidArgumentException('The iCal UID must be non-empty and contain no control characters.');
        }

        if ($endDate <= $startDate) {
            throw new InvalidArgumentException('The iCal end date must be later than the start date.');
        }

        foreach ([$startDate, $endDate] as $date) {
            if ($date->getTimezone()->getName() !== 'Europe/Budapest' || $date->format('H:i:s') !== '00:00:00') {
                throw new InvalidArgumentException('iCal booking periods must be Budapest whole calendar dates.');
            }
        }
    }
}
