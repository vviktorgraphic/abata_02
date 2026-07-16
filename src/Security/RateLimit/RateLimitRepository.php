<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

/** Persistence boundary; implementations must update counters atomically. */
interface RateLimitRepository
{
    public function countFailures(string $scope, string $keyHash, \DateTimeImmutable $since): int;

    public function recordFailure(string $scope, string $keyHash, \DateTimeImmutable $occurredAt): void;

    public function clearFailures(string $scope, string $keyHash): void;

    public function lockedUntil(string $scope, string $keyHash): ?\DateTimeImmutable;

    public function lock(string $scope, string $keyHash, \DateTimeImmutable $until): void;
}
