<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;

interface AdminManualBookingWorkflow
{
    public function preview(BookingCreateRequest $request): AdminManualBookingPreview;

    public function create(BookingCreateRequest $request, int $adminId, string $expectedTotal, string $expectedCurrency): BookingPersistenceResult;
}
