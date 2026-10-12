<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;
use App\Domain\Pricing\PricingResult;

final readonly class AdminManualBookingPreview
{
    public function __construct(public BookingCreateRequest $request, public PricingResult $pricing)
    {
    }
}
