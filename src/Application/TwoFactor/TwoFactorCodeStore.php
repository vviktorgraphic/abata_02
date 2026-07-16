<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

use App\Domain\TwoFactor\TwoFactorCode;
use DateTimeImmutable;

interface TwoFactorCodeStore
{
    /**
     * Atomically enforces the cooldown, invalidates active codes and persists the replacement hash.
     * Implementations must never receive or persist the raw code.
     */
    public function replaceActiveIfAllowed(
        int $adminId,
        TwoFactorCode $code,
        DateTimeImmutable $sentAt,
        DateTimeImmutable $latestAllowedPreviousSend,
    ): bool;
}
