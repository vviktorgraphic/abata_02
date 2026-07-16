<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingCreateOutcome
{
    public function __construct(
        public string $reference,
        public string $status,
        public string $totalAmount,
        public string $currency,
        public string $emailStatus,
        public bool $replayed,
    ) {
    }
}
