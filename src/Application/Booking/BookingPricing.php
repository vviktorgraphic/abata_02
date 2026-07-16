<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingPricing
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $totalAmount,
        public string $currency,
        public array $snapshot,
    ) {
        if ($currency !== 'HUF' || !preg_match('/^\d+\.\d{2}$/', $totalAmount)) {
            throw new \InvalidArgumentException('Booking pricing must contain a HUF decimal amount.');
        }
    }
}
