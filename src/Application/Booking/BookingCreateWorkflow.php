<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;

interface BookingCreateWorkflow
{
    public function create(BookingCreateRequest $request): BookingCreateOutcome;
}
