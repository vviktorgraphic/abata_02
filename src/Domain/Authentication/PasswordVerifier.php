<?php

declare(strict_types=1);

namespace App\Domain\Authentication;

interface PasswordVerifier
{
    /** Verifies a plaintext password against a PHP password hash. */
    public function verify(string $plainPassword, string $passwordHash): bool;

    /** Indicates whether the stored hash should be upgraded. */
    public function needsRehash(string $passwordHash): bool;

    /** Creates a password hash using the runtime's recommended algorithm. */
    public function hash(string $plainPassword): string;
}
