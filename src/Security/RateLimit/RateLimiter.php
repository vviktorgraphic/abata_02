<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

/** Fixed-window calculator suitable for an atomic MySQL repository. */
final readonly class RateLimiter
{
    public function __construct(
        private RateLimitRepository $repository,
        private RateLimitClock $clock,
        private string $keyPepper,
    ) {
        if ($keyPepper === '') {
            throw new \InvalidArgumentException('A non-empty rate-limit key pepper is required.');
        }
    }

    public function check(RateLimitPolicy $policy, string $rawKey): RateLimitDecision
    {
        $now = $this->clock->now();
        $hash = $this->hashKey($policy->scope, $rawKey);
        $lockedUntil = $this->repository->lockedUntil($policy->scope, $hash);
        if ($lockedUntil !== null && $lockedUntil > $now) {
            return new RateLimitDecision(false, $policy->maximumFailures, $lockedUntil);
        }

        $failures = $this->repository->countFailures(
            $policy->scope,
            $hash,
            $now->modify(sprintf('-%d seconds', $policy->windowSeconds)),
        );

        return new RateLimitDecision($failures < $policy->maximumFailures, $failures);
    }

    public function recordFailure(RateLimitPolicy $policy, string $rawKey): RateLimitDecision
    {
        $now = $this->clock->now();
        $hash = $this->hashKey($policy->scope, $rawKey);
        $this->repository->recordFailure($policy->scope, $hash, $now);
        $failures = $this->repository->countFailures($policy->scope, $hash, $now->modify(sprintf('-%d seconds', $policy->windowSeconds)));
        if ($failures >= $policy->maximumFailures) {
            $until = $now->modify(sprintf('+%d seconds', $policy->lockoutSeconds));
            $this->repository->lock($policy->scope, $hash, $until);
            return new RateLimitDecision(false, $failures, $until);
        }

        return new RateLimitDecision(true, $failures);
    }

    public function recordSuccess(RateLimitPolicy $policy, string $rawKey): void
    {
        $this->repository->clearFailures($policy->scope, $this->hashKey($policy->scope, $rawKey));
    }

    private function hashKey(string $scope, string $rawKey): string
    {
        if ($rawKey === '') {
            throw new \InvalidArgumentException('A rate-limit key cannot be empty.');
        }
        return hash_hmac('sha256', $scope."\0".$rawKey, $this->keyPepper);
    }
}
