<?php

declare(strict_types=1);

namespace App\Domain\Booking;

final readonly class CancellationResult
{
    /** @param array<string, int|float|string> $snapshot */
    public function __construct(
        public string $cancelledAt,
        public string $penaltyRate,
        public string $penaltyAmount,
        public string $currency,
        public int $ruleVersion,
        public array $snapshot,
    ) {
    }

    public function hasPenalty(): bool
    {
        return $this->penaltyAmount !== '0.00';
    }
}
