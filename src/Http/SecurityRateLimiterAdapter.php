<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;

final readonly class SecurityRateLimiterAdapter implements BookingRequestRateLimiter
{
    public function __construct(private RateLimiter $limiter, private RateLimitPolicy $policy)
    {
    }

    public function allow(string $clientAddress): bool
    {
        if (!$this->limiter->check($this->policy, $clientAddress)->allowed) {
            return false;
        }

        $this->limiter->recordFailure($this->policy, $clientAddress);
        return true;
    }
}
