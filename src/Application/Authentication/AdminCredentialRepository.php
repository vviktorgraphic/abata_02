<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Domain\Authentication\AdminCredential;

interface AdminCredentialRepository
{
    /** Looks up an administrator by its already-normalized e-mail address. */
    public function findByNormalizedEmail(string $normalizedEmail): ?AdminCredential;

    /** Replaces a password hash after a successful credential check. */
    public function updatePasswordHash(int $adminId, string $passwordHash): void;
}
