<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PricingResult
{
    /** @param list<array<string,mixed>> $lineItems @param list<int> $appliedRuleIds @param array<string,mixed> $snapshot */
    public function __construct(
        public string $totalAmount,
        public string $accommodationFee,
        public string $tourismTax,
        public string $currency,
        public array $lineItems,
        public array $appliedRuleIds,
        public array $snapshot,
    ) {
    }
}
