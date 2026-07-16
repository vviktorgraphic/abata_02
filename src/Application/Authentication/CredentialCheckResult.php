<?php

declare(strict_types=1);

namespace App\Application\Authentication;

final readonly class CredentialCheckResult
{
    private function __construct(
        public bool $accepted,
        public ?int $adminId,
        public ?string $normalizedEmail,
    ) {
    }

    /** Creates the password-accepted, 2FA-pending result. */
    public static function accepted(int $adminId, string $normalizedEmail): self
    {
        return new self(true, $adminId, $normalizedEmail);
    }

    /** Creates the intentionally detail-free rejection result. */
    public static function rejected(): self
    {
        return new self(false, null, null);
    }
}
