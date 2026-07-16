<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DomainException;

final class BookingOverlap extends DomainException
{
    public function __construct(public readonly BookingStatus $blockingStatus)
    {
        parent::__construct('The requested period is not available.');
    }
}
