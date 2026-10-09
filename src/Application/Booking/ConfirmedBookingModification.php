<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class ConfirmedBookingModification
{
    /** @param list<int> $childAges */
    public function __construct(
        public string $arrivalDate,
        public string $departureDate,
        public int $adults,
        public array $childAges,
    ) {
    }

    public function nights(): int
    {
        return (int) (new \DateTimeImmutable($this->arrivalDate))->diff(new \DateTimeImmutable($this->departureDate))->days;
    }

    /** @return array{arrival_date:string,departure_date:string,adults:int,child_ages:list<int>} */
    public function canonicalPayload(): array
    {
        return [
            'arrival_date' => $this->arrivalDate,
            'departure_date' => $this->departureDate,
            'adults' => $this->adults,
            'child_ages' => $this->childAges,
        ];
    }
}
