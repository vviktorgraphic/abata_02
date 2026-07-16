<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

use App\Domain\TwoFactor\TwoFactorClock;
use App\Domain\TwoFactor\TwoFactorCodeRejected;

final readonly class VerifyTwoFactorCode
{
    public function __construct(
        private TwoFactorVerificationStore $store,
        private TwoFactorClock $clock,
    ) {
    }

    /** Verifies and atomically persists the consumed or failed code state. */
    public function verify(int $adminId, string $candidate): bool
    {
        $code = $this->store->findActiveForUpdate($adminId);
        if ($code === null) {
            return false;
        }

        try {
            $code->verify($candidate, $this->clock->now());
            $this->store->saveState($adminId, $code);
            return true;
        } catch (TwoFactorCodeRejected) {
            $this->store->saveState($adminId, $code);
            return false;
        }
    }
}

