<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DomainException;

final class BookingTransitionNotAllowed extends DomainException
{
    public function __construct(
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
    ) {
        parent::__construct(sprintf(
            'Cannot transition booking from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
