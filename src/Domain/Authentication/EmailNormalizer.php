<?php

declare(strict_types=1);

namespace App\Domain\Authentication;

final class EmailNormalizer
{
    public const MAX_LENGTH = 190;

    /** Returns the canonical lookup form, or null for invalid input. */
    public function normalize(string $email): ?string
    {
        $normalized = strtolower(trim($email));

        if ($normalized === '' || strlen($normalized) > self::MAX_LENGTH) {
            return null;
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            ? $normalized
            : null;
    }
}
