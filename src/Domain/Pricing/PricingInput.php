<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PricingInput
{
    /** @param list<int> $childAges @param list<string> $exemptionKeys */
    public function __construct(
        public string $arrivalDate,
        public string $departureDate,
        public int $adults,
        public array $childAges = [],
        public array $exemptionKeys = [],
    ) {
        if ($adults < 0 || array_filter($childAges, static fn (mixed $age): bool => !is_int($age) || $age < 0) !== []) {
            throw new \InvalidArgumentException('Guest counts and ages must be non-negative integers.');
        }
        if ($adults + count($childAges) < 1) {
            throw new \InvalidArgumentException('At least one guest is required.');
        }
    }
}
