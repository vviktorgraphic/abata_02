<?php

declare(strict_types=1);

namespace App\Domain\Booking;

final readonly class BookingOverlapPolicy
{
    public function __construct(private AvailabilityService $availabilityService)
    {
    }

    /**
     * @param iterable<array{period: BookingPeriod, status: BookingStatus}> $existingBookings
     */
    public function assertPublicRequestAllowed(BookingPeriod $requested, iterable $existingBookings): void
    {
        foreach ($existingBookings as $booking) {
            if ($booking['status']->blocksPublicBooking()
                && $this->availabilityService->overlaps($requested, $booking['period'])) {
                throw new BookingOverlap($booking['status']);
            }
        }
    }
}
