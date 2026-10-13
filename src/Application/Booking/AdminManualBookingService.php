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
        private AdminPricingOverride $pricingOverride = new AdminPricingOverride(),
    ) {
    }

    public function preview(BookingCreateRequest $request, ?AdminCustomPrice $customPrice = null): AdminManualBookingPreview
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

        $calculated = $this->pricingPreviewer->preview(new PricingInput(
            $period->arrival->format('Y-m-d'),
            $period->departure->format('Y-m-d'),
            $request->adults,
            $request->childAges,
        ));
        $effective = $this->pricingOverride->applyToResult($calculated, $customPrice);

        return new AdminManualBookingPreview(
            $request,
            $calculated,
            $effective,
            $customPrice?->total,
            PricingSnapshotHasher::hash($effective->snapshot),
        );
    }

    public function create(
        BookingCreateRequest $request,
        int $adminId,
        string $expectedCalculatedTotal,
        string $expectedPricingHash,
        ?AdminCustomPrice $customPrice,
        string $expectedEffectiveTotal,
        string $expectedCurrency,
    ): BookingPersistenceResult {
        $requestHash = hash('sha256', json_encode([
            'request' => $request->canonicalPayload(),
            'custom_total' => $customPrice?->total,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $command = new BookingPersistenceCommand(
            $request->idempotencyKey,
            $requestHash,
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
            BookingCreationOptions::adminManual(
                $adminId,
                $expectedCalculatedTotal,
                $expectedPricingHash,
                $customPrice?->total,
                $expectedEffectiveTotal,
                $expectedCurrency,
            ),
        );
    }

    private function reference(): string
    {
        return 'AB-' . $this->clock->now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
    }
}
