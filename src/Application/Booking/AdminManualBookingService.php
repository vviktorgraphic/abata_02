<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\Availability\BlockedPeriodReadRepository;
use App\Application\Availability\BookingReadRepository;
use App\Application\Pricing\PricingPreviewer;
use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingCreateRequest;
use App\Domain\Pricing\PricingInput;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;

final readonly class AdminManualBookingService implements AdminManualBookingWorkflow
{
    public function __construct(
        private TransactionalBookingRepository $repository,
        private BookingPricingProvider $pricingProvider,
        private PricingPreviewer $pricingPreviewer,
        private BookingReadRepository $bookings,
        private BlockedPeriodReadRepository $blockedPeriods,
        private BookingClock $clock,
    ) {
    }

    public function preview(BookingCreateRequest $request): AdminManualBookingPreview
    {
        $period = $request->period;
        $occupied = [
            ...$this->bookings->findBlockingBetween($period->arrival, $period->departure),
            ...$this->blockedPeriods->findBetween($period->arrival, $period->departure),
        ];
        $availability = new AvailabilityService($this->clock->now()->setTime(0, 0));
        if (!$availability->isAvailable($period, $occupied)) {
            throw new BookingConflict('A megadott időszak foglalt vagy aktív blokkolással ütközik.');
        }

        return new AdminManualBookingPreview($request, $this->pricingPreviewer->preview(new PricingInput(
            $period->arrival->format('Y-m-d'),
            $period->departure->format('Y-m-d'),
            $request->adults,
            $request->childAges,
        )));
    }

    public function create(BookingCreateRequest $request, int $adminId, string $expectedTotal, string $expectedCurrency): BookingPersistenceResult
    {
        $command = new BookingPersistenceCommand(
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
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        return $this->repository->create(
            $command,
            $this->pricingProvider,
            BookingCreationOptions::adminManual($adminId, $expectedTotal, $expectedCurrency),
        );
    }

    private function reference(): string
    {
        return 'AB-' . $this->clock->now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }
}
