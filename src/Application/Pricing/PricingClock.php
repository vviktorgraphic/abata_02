<?php

declare(strict_types=1);

namespace App\Application\Pricing;

interface PricingClock
{
    public function now(): \DateTimeImmutable;
}
