<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Domain\Authentication\EmailNormalizer;
use App\Domain\Authentication\PasswordVerifier;

final class AuthenticationService
{
    /**
     * The dummy hash must be a valid password_hash() output and must not encode
     * any real credential. It equalizes the unknown-account verification path.
     */
    public function __construct(
        private readonly AdminCredentialRepository $admins,
        private readonly PasswordVerifier $passwords,
        private readonly EmailNormalizer $emails,
        private readonly string $dummyPasswordHash,
    ) {
        if (password_get_info($this->dummyPasswordHash)['algo'] === null) {
            throw new \InvalidArgumentException('The dummy password hash is invalid.');
        }
    }

    /** Checks the password without granting an authenticated admin session. */
    public function checkCredentials(string $email, string $plainPassword): CredentialCheckResult
    {
        $normalizedEmail = $this->emails->normalize($email);
        $admin = $normalizedEmail === null
            ? null
            : $this->admins->findByNormalizedEmail($normalizedEmail);

        $hash = $admin?->passwordHash ?? $this->dummyPasswordHash;
        $passwordMatches = $this->passwords->verify($plainPassword, $hash);

        if ($admin === null || !$admin->isActive || !$passwordMatches) {
            return CredentialCheckResult::rejected();
        }

        if ($this->passwords->needsRehash($admin->passwordHash)) {
            $this->admins->updatePasswordHash($admin->id, $this->passwords->hash($plainPassword));
        }

        return CredentialCheckResult::accepted($admin->id, $normalizedEmail);
    }
}
