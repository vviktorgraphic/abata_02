<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

final readonly class AdminNote
{
    public const MAX_LENGTH = 500;

    public ?string $value;

    public function __construct(?string $value)
    {
        if ($value !== null && mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Admin note must not exceed %d characters.', self::MAX_LENGTH));
        }

        $this->value = $value;
    }
}
