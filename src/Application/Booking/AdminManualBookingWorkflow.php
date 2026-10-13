<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;

interface AdminManualBookingWorkflow
{
    public function preview(BookingCreateRequest $request, ?AdminCustomPrice $customPrice = null): AdminManualBookingPreview;

    public function create(
        BookingCreateRequest $request,
        int $adminId,
        string $expectedCalculatedTotal,
        string $expectedPricingHash,
        ?AdminCustomPrice $customPrice,
        string $expectedEffectiveTotal,
        string $expectedCurrency,
    ): BookingPersistenceResult;
}
