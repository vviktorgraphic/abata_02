<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use DateTimeImmutable;

final readonly class IcsEvent
{
    public function __construct(
        public string $uid,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool $allDay,
        public ?DateTimeImmutable $dtstamp,
        public int $sequence,
        public ?string $status,
        public string $summary,
        public string $description,
        public ?DateTimeImmutable $lastModified,
    ) {
    }

    public function arrivalDate(): string
    {
        return $this->startsAt->format('Y-m-d');
    }

    /** Departure remains exclusive for VALUE=DATE events. */
    public function departureDate(): string
    {
        return $this->endsAt->format('Y-m-d');
    }
}
