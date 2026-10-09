<?php
declare(strict_types=1);
namespace App\Application\Booking;
final readonly class BookingModificationResult
{
    public function __construct(
        public int $bookingId,
        public int $modificationId,
        public int $version,
        public bool $idempotentReplay = false,
    ) {}
}
