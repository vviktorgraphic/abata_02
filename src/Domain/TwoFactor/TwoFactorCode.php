<?php

declare(strict_types=1);

namespace App\Domain\TwoFactor;

use DateTimeImmutable;
use DomainException;

final class TwoFactorCode
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @param non-empty-string $codeHash A password_hash()-compatible hash; never a raw code.
     */
    public function __construct(
        private readonly string $codeHash,
        private readonly DateTimeImmutable $expiresAt,
        private int $attemptCount = 0,
        private ?DateTimeImmutable $usedAt = null,
        private ?DateTimeImmutable $invalidatedAt = null,
    ) {
        if ($codeHash === '') {
            throw new DomainException('A 2FA code hash is required.');
        }
        if ($attemptCount < 0 || $attemptCount > self::MAX_ATTEMPTS) {
            throw new DomainException('The 2FA attempt count is invalid.');
        }
    }

    /**
     * Verifies and consumes the code. Failed comparisons increment the attempt count.
     *
     * @throws TwoFactorCodeRejected when the code is unusable or does not match
     */
    public function verify(string $candidate, DateTimeImmutable $now): void
    {
        $this->assertUsable($now);

        if (!preg_match('/^\d{6}$/D', $candidate) || !password_verify($candidate, $this->codeHash)) {
            ++$this->attemptCount;
            throw TwoFactorCodeRejected::invalid();
        }

        $this->usedAt = $now;
    }

    /** Invalidates this code so it can never be accepted again. */
    public function invalidate(DateTimeImmutable $now): void
    {
        if ($this->invalidatedAt === null) {
            $this->invalidatedAt = $now;
        }
    }

    /** Returns the opaque password hash safe for persistence. */
    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function usedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function invalidatedAt(): ?DateTimeImmutable
    {
        return $this->invalidatedAt;
    }

    private function assertUsable(DateTimeImmutable $now): void
    {
        if ($this->usedAt !== null) {
            throw TwoFactorCodeRejected::used();
        }
        if ($this->invalidatedAt !== null) {
            throw TwoFactorCodeRejected::invalidated();
        }
        if ($now >= $this->expiresAt) {
            throw TwoFactorCodeRejected::expired();
        }
        if ($this->attemptCount >= self::MAX_ATTEMPTS) {
            throw TwoFactorCodeRejected::attemptsExhausted();
        }
    }
}
