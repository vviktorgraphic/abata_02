<?php

declare(strict_types=1);

namespace App\Domain\Booking;

enum BookingStatus: string
{
    /** @var list<string> */
    public const BLOCKING_VALUES = ['pending', 'confirmed'];

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Invalidated = 'invalidated';
    case Completed = 'completed';

    public function blocksPublicBooking(): bool
    {
        return in_array($this->value, self::BLOCKING_VALUES, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Függőben',
            self::Confirmed => 'Megerősítve',
            self::Rejected => 'Elutasítva',
            self::Cancelled => 'Lemondva',
            self::Invalidated => 'Érvénytelenítve',
            self::Completed => 'Teljesült',
        };
    }
}
