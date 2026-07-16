<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingPersistenceResult
{
    public function __construct(
        public int $bookingId,
        public string $reference,
        public string $status,
        public string $totalAmount,
        public string $currency,
        public bool $replayed,
    ) {
    }
}
