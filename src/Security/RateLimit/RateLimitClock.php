<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

interface RateLimitClock
{
    public function now(): \DateTimeImmutable;
}
