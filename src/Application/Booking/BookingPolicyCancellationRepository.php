<?php

declare(strict_types=1);

namespace App\Application\Booking;

interface BookingPolicyCancellationRepository
{
    public function savePolicyAcceptance(
        int $bookingId,
        string $acceptedAt,
        string $version,
        string $url,
    ): bool;

    /** @param array<string, mixed> $snapshot */
    public function saveCancellationSnapshot(
        int $bookingId,
        string $cancelledAt,
        string $penaltyRate,
        string $penaltyAmount,
        string $currency,
        int $ruleVersion,
        array $snapshot,
    ): bool;
}
