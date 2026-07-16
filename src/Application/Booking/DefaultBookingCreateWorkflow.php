<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;

final readonly class DefaultBookingCreateWorkflow implements BookingCreateWorkflow
{
    public function __construct(
        private TransactionalBookingRepository $repository,
        private BookingPricingProvider $pricing,
        private BookingOutboxDispatcher $outbox,
        private BookingClock $clock = new BudapestBookingClock(),
    ) {
    }

    public function create(BookingCreateRequest $request): BookingCreateOutcome
    {
        $result = $this->repository->create(new BookingPersistenceCommand(
            $request->idempotencyKey,
            $request->canonicalHash(),
            $this->reference(),
            $request->period->arrival->format('Y-m-d'),
            $request->period->departure->format('Y-m-d'),
            $request->contactName,
            $request->email,
            $request->phone,
            $request->adults,
            $request->childAges,
            $request->notes === '' ? null : $request->notes,
        ), $this->pricing);

        // Persistence has committed before SMTP is attempted. A delivery failure
        // therefore leaves one retryable pending outbox item and never loses the booking.
        $emailStatus = $this->outbox->dispatchForBooking($result->bookingId);

        return new BookingCreateOutcome(
            $result->reference,
            $result->status,
            $result->totalAmount,
            $result->currency,
            $emailStatus,
            $result->replayed,
        );
    }

    private function reference(): string
    {
        return 'AB-' . $this->clock->now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }
}
