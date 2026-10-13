<?php

declare(strict_types=1);

namespace App\Application\Booking;

final class PricingSnapshotHasher
{
    /** @param array<string,mixed> $snapshot */
    public static function hash(array $snapshot): string
    {
        unset($snapshot['calculated_at']);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
