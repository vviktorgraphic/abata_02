<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;

final readonly class DayAvailability
{
    public function __construct(
        public DateTimeImmutable $date,
        public AvailabilityStatus $status,
        public bool $selectableAsArrival,
        public bool $selectableAsDeparture,
    ) {
    }

    /** @return array{date: string, status: string, selectable_as_arrival: bool, selectable_as_departure: bool} */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'status' => $this->status->value,
            'selectable_as_arrival' => $this->selectableAsArrival,
            'selectable_as_departure' => $this->selectableAsDeparture,
        ];
    }
}

