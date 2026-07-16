<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

/** Groups independently configurable login and 2FA protections. */
final readonly class AuthenticationRateLimitPolicies
{
    public function __construct(
        public RateLimitPolicy $loginByIp,
        public RateLimitPolicy $loginByAccount,
        public RateLimitPolicy $twoFactorVerify,
        public RateLimitPolicy $twoFactorResend,
    ) {
        if ($twoFactorVerify->maximumFailures > 5) {
            throw new \InvalidArgumentException('2FA verification may allow at most five failures.');
        }
    }
}
