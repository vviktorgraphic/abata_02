<?php

declare(strict_types=1);

namespace App\Application\Mail;

final class PaymentReference
{
    public static function forBookingId(int $bookingId): string
    {
        if ($bookingId < 1) {
            throw new \InvalidArgumentException('A foglalás azonosítója érvénytelen.');
        }
        return sprintf('AB-%06d', $bookingId);
    }
}
