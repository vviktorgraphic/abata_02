<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

use App\Domain\TwoFactor\TwoFactorCode;

interface TwoFactorVerificationStore
{
    /** Loads the current code under a persistence lock when supported. */
    public function findActiveForUpdate(int $adminId): ?TwoFactorCode;

    /** Persists attempt, use and invalidation state; plaintext is never accepted. */
    public function saveState(int $adminId, TwoFactorCode $code): void;
}
