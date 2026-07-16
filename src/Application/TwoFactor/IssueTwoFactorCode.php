<?php

declare(strict_types=1);

namespace App\Application\TwoFactor;

use App\Domain\TwoFactor\GeneratedTwoFactorCode;
use App\Domain\TwoFactor\TwoFactorClock;
use App\Domain\TwoFactor\TwoFactorCodeGenerator;
use DomainException;

final readonly class IssueTwoFactorCode
{
    public function __construct(
        private TwoFactorCodeStore $store,
        private TwoFactorCodeGenerator $generator,
        private TwoFactorClock $clock,
    ) {
    }

    /**
     * Issues a replacement code after enforcing the sixty-second resend cooldown.
     * The returned plaintext must only be passed to the mail delivery boundary.
     */
    public function issue(int $adminId): GeneratedTwoFactorCode
    {
        $now = $this->clock->now();
        $generated = $this->generator->generate();
        if (!$this->store->replaceActiveIfAllowed(
            $adminId,
            $generated->code,
            $now,
            $now->modify('-60 seconds'),
        )) {
            throw new DomainException('A two-factor code was sent too recently.');
        }

        return $generated;
    }
}
