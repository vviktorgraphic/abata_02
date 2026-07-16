<?php

declare(strict_types=1);

namespace App\Application\Booking;

interface BookingClock
{
    public function now(): \DateTimeImmutable;
}
