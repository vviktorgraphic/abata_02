<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;

final readonly class SecurityAdminActionRateLimiter implements AdminActionRateLimiter
{
    public function __construct(private RateLimiter $limiter, private RateLimitPolicy $policy)
    {
    }

    public function allow(int $adminId, string $action): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $action)) {
            throw new \InvalidArgumentException('Invalid admin action rate-limit key.');
        }

        $key = $adminId . ':' . $action;
        if (!$this->limiter->check($this->policy, $key)->allowed) {
            return false;
        }

        // Each dangerous state-change is an attempt, regardless of its business outcome.
        return $this->limiter->recordFailure($this->policy, $key)->allowed;
    }
}
