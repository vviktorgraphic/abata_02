<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class EmailOutboxRetryPolicy
{
    public const MAX_ATTEMPTS = 5;
    public const STALE_PROCESSING_SECONDS = 900;

    /** @var array<int, int> */
    private const BACKOFF_SECONDS = [
        1 => 300,
        2 => 900,
        3 => 3600,
        4 => 21600,
    ];

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    public function staleProcessingSeconds(): int
    {
        return self::STALE_PROCESSING_SECONDS;
    }

    public function retryDelaySeconds(int $attempts): ?int
    {
        return self::BACKOFF_SECONDS[$attempts] ?? null;
    }

    public function canAttempt(int $attempts): bool
    {
        return $attempts >= 0 && $attempts < self::MAX_ATTEMPTS;
    }

    public function isDue(
        string $status,
        int $attempts,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $now,
    ): bool {
        if ($status === 'pending') {
            return $attempts === 0;
        }
        $delay = $status === 'failed' ? $this->retryDelaySeconds($attempts) : null;

        return $delay !== null && $updatedAt->modify('+' . $delay . ' seconds') <= $now;
    }

    public function isStale(\DateTimeImmutable $updatedAt, \DateTimeImmutable $now): bool
    {
        return $updatedAt->modify('+' . self::STALE_PROCESSING_SECONDS . ' seconds') <= $now;
    }
}
