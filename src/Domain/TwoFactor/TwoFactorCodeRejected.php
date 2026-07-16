<?php

declare(strict_types=1);

namespace App\Domain\TwoFactor;

use DomainException;

final class TwoFactorCodeRejected extends DomainException
{
    private function __construct(private readonly string $reason)
    {
        parent::__construct('The two-factor code was rejected.');
    }

    public static function invalid(): self { return new self('invalid'); }
    public static function expired(): self { return new self('expired'); }
    public static function used(): self { return new self('used'); }
    public static function invalidated(): self { return new self('invalidated'); }
    public static function attemptsExhausted(): self { return new self('attempts_exhausted'); }

    /** Returns a machine-readable reason for application/audit decisions. */
    public function reason(): string
    {
        return $this->reason;
    }
}
