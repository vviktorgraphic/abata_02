<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class AdminBookingDetailQuery
{
    public function __construct(public string $referenceOrId)
    {
        if (trim($referenceOrId) === '') {
            throw new \InvalidArgumentException('Booking identifier is required.');
        }
    }
}
