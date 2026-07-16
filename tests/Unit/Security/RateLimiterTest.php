<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\RateLimit\RateLimitClock;
use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;
use App\Security\RateLimit\RateLimitRepository;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testThresholdLocksKeyAndWindowIsPassedToRepository(): void
    {
        $now = new \DateTimeImmutable('2026-07-16 10:00:00', new \DateTimeZone('Europe/Budapest'));
        $repository = new InMemoryRateLimitRepository();
        $limiter = new RateLimiter($repository, new FixedRateLimitClock($now), 'test-pepper');
        $policy = new RateLimitPolicy('admin_login_ip', 3, 300, 600);

        self::assertTrue($limiter->recordFailure($policy, '192.0.2.1')->allowed);
        self::assertTrue($limiter->recordFailure($policy, '192.0.2.1')->allowed);
        $decision = $limiter->recordFailure($policy, '192.0.2.1');

        self::assertFalse($decision->allowed);
        self::assertSame(3, $decision->failures);
        self::assertEquals($now->modify('+600 seconds'), $decision->lockedUntil);
        self::assertSame($now->modify('-300 seconds')->getTimestamp(), $repository->lastSince?->getTimestamp());
        self::assertStringNotContainsString('192.0.2.1', implode('', array_keys($repository->failures)));
    }

    public function testSuccessClearsOnlyMatchingBucket(): void
    {
        $now = new \DateTimeImmutable('2026-07-16 10:00:00', new \DateTimeZone('Europe/Budapest'));
        $repository = new InMemoryRateLimitRepository();
        $limiter = new RateLimiter($repository, new FixedRateLimitClock($now), 'test-pepper');
        $policy = new RateLimitPolicy('admin_login_account', 2, 60, 60);
        $limiter->recordFailure($policy, 'admin-1');
        $limiter->recordSuccess($policy, 'admin-1');

        self::assertTrue($limiter->check($policy, 'admin-1')->allowed);
        self::assertSame(0, $limiter->check($policy, 'admin-1')->failures);
    }
}

final class FixedRateLimitClock implements RateLimitClock
{
    public function __construct(private readonly \DateTimeImmutable $now) {}
    public function now(): \DateTimeImmutable { return $this->now; }
}

final class InMemoryRateLimitRepository implements RateLimitRepository
{
    /** @var array<string, list<\DateTimeImmutable>> */
    public array $failures = [];
    /** @var array<string, \DateTimeImmutable> */
    private array $locks = [];
    public ?\DateTimeImmutable $lastSince = null;

    public function countFailures(string $scope, string $keyHash, \DateTimeImmutable $since): int
    {
        $this->lastSince = $since;
        return count(array_filter($this->failures[$scope.$keyHash] ?? [], static fn (\DateTimeImmutable $date): bool => $date >= $since));
    }
    public function recordFailure(string $scope, string $keyHash, \DateTimeImmutable $occurredAt): void { $this->failures[$scope.$keyHash][] = $occurredAt; }
    public function clearFailures(string $scope, string $keyHash): void { unset($this->failures[$scope.$keyHash], $this->locks[$scope.$keyHash]); }
    public function lockedUntil(string $scope, string $keyHash): ?\DateTimeImmutable { return $this->locks[$scope.$keyHash] ?? null; }
    public function lock(string $scope, string $keyHash, \DateTimeImmutable $until): void { $this->locks[$scope.$keyHash] = $until; }
}
