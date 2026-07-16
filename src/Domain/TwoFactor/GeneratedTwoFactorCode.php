<?php

declare(strict_types=1);

namespace App\Domain\TwoFactor;

final readonly class GeneratedTwoFactorCode
{
    /** @param string $plainCode Six digits intended only for immediate delivery. */
    public function __construct(
        public string $plainCode,
        public TwoFactorCode $code,
    ) {
    }
}
