<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BlockedPeriodCreation
{
    /** @param list<string> $overlappingPendingReferences */
    public function __construct(public int $id, public array $overlappingPendingReferences)
    {
    }
}
