<?php

declare(strict_types=1);

namespace App\Application\Pricing;

final class BudapestPricingClock implements PricingClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest'));
    }
}
