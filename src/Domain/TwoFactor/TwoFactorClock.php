<?php

declare(strict_types=1);

namespace App\Domain\TwoFactor;

use DateTimeImmutable;

interface TwoFactorClock
{
    /** Returns the current time as an immutable Budapest-local instant. */
    public function now(): DateTimeImmutable;
}
