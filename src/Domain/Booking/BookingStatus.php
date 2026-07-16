<?php

declare(strict_types=1);

namespace App\Domain\Booking;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Invalidated = 'invalidated';

    public function blocksPublicBooking(): bool
    {
        return $this === self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Függőben',
            self::Confirmed => 'Megerősítve',
            self::Rejected => 'Elutasítva',
            self::Cancelled => 'Lemondva',
            self::Invalidated => 'Érvénytelenítve',
        };
    }
}
