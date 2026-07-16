<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingTransitionResult
{
    public function __construct(
        public int $bookingId,
        public string $reference,
        public string $oldStatus,
        public string $newStatus,
        public bool $notificationQueued,
    ) {
    }
}
