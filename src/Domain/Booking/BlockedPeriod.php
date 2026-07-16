<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;

final readonly class BlockedPeriod
{
    public function __construct(
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public string $reason,
        public ?string $internalNote = null,
    ) {
        if ($endDate <= $startDate) {
            throw new BlockedPeriodInvalid('Blocked period end must be after its start.');
        }
        if ($startDate->format('H:i:s') !== '00:00:00' || $endDate->format('H:i:s') !== '00:00:00') {
            throw new BlockedPeriodInvalid('Blocked periods must contain calendar dates.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new BlockedPeriodInvalid('Reason must contain between 1 and 500 characters.');
        }
        if ($internalNote !== null && mb_strlen($internalNote) > 500) {
            throw new BlockedPeriodInvalid('Internal note must not exceed 500 characters.');
        }
    }

    public function overlaps(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $this->startDate < $end && $this->endDate > $start;
    }
}
