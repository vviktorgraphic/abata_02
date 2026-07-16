<?php

declare(strict_types=1);

namespace App\Application\Pricing;

use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingResult;

/** Shared pricing boundary used by both admin preview and booking creation. */
interface PricingPreviewer
{
    public function preview(PricingInput $input): PricingResult;
}
