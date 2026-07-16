<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $failures,
        public ?\DateTimeImmutable $lockedUntil = null,
    ) {
    }
}
