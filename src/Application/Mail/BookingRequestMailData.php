<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingRequestMailData
{
    /** @param list<int> $childAges */
    public function __construct(
        public string $recipient,
        public string $reference,
        public string $arrivalDate,
        public string $departureDate,
        public int $adults,
        public array $childAges,
        public string $totalAmount,
        public string $currency,
    ) {
    }

    public function nights(): int
    {
        $arrival = new \DateTimeImmutable($this->arrivalDate, new \DateTimeZone('Europe/Budapest'));
        $departure = new \DateTimeImmutable($this->departureDate, new \DateTimeZone('Europe/Budapest'));

        return (int) $arrival->diff($departure)->days;
    }
}
