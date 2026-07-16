<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitPolicy
{
    public function __construct(
        public string $scope,
        public int $maximumFailures,
        public int $windowSeconds,
        public int $lockoutSeconds,
    ) {
        if ($scope === '' || $maximumFailures < 1 || $windowSeconds < 1 || $lockoutSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit policy values must be positive.');
        }
    }
}
