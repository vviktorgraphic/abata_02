<?php

declare(strict_types=1);

namespace App\Domain\Booking;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function blocksPublicBooking(): bool
    {
        return $this === self::Confirmed;
    }
}
