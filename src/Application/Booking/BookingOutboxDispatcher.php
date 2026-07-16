<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\Mail\BookingRequestOutboxDispatcher;

final readonly class BookingOutboxDispatcher
{
    public function __construct(private BookingRequestOutboxDispatcher $dispatcher)
    {
    }

    /** @return 'pending'|'sent'|'failed' */
    public function dispatchForBooking(int $bookingId): string
    {
        return $this->dispatcher->dispatchForBooking($bookingId)->status;
    }
}
