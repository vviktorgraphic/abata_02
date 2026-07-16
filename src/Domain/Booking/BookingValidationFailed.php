<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DomainException;

final class BookingValidationFailed extends DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The booking request is invalid.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
