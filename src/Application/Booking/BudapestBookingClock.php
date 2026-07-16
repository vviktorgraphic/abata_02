<?php

declare(strict_types=1);

namespace App\Application\Booking;

final class BudapestBookingClock implements BookingClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest'));
    }
}
