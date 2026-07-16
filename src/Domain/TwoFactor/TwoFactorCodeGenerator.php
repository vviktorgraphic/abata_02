<?php

declare(strict_types=1);

namespace App\Domain\TwoFactor;

use DateInterval;
use RuntimeException;

final readonly class TwoFactorCodeGenerator
{
    public function __construct(private TwoFactorClock $clock)
    {
    }

    /** Generates a cryptographically secure code with a ten-minute lifetime. */
    public function generate(): GeneratedTwoFactorCode
    {
        $plainCode = sprintf('%06d', random_int(0, 999999));
        $hash = password_hash($plainCode, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash the two-factor code.');
        }

        return new GeneratedTwoFactorCode(
            $plainCode,
            new TwoFactorCode($hash, $this->clock->now()->add(new DateInterval('PT10M'))),
        );
    }
}
