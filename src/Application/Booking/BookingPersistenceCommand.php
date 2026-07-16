<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingPersistenceCommand
{
    /** @param list<int> $childAges */
    public function __construct(
        public string $idempotencyKey,
        public string $requestHash,
        public string $reference,
        public string $arrivalDate,
        public string $departureDate,
        public string $contactName,
        public string $email,
        public string $phone,
        public int $adults,
        public array $childAges,
        public ?string $notes,
    ) {
        if (!preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new \InvalidArgumentException('The canonical request hash must be a lowercase SHA-256 hex value.');
        }
    }
}
