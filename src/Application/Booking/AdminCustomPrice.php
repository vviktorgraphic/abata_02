<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Pricing\WholeHuf;

final readonly class AdminCustomPrice
{
    private function __construct(public string $total)
    {
    }

    public static function optionalFromInput(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new AdminCustomPriceInvalid('Az egyedi végösszeg egész, nem negatív forintösszeg lehet.');
        }

        try {
            return new self(WholeHuf::fromInput(trim($value)));
        } catch (\InvalidArgumentException) {
            throw new AdminCustomPriceInvalid('Az egyedi végösszeg egész, nem negatív forintösszeg lehet.');
        }
    }

    public static function fromCanonical(string $value): self
    {
        try {
            return new self(WholeHuf::normalize($value));
        } catch (\InvalidArgumentException) {
            throw new AdminCustomPriceInvalid('Az egyedi végösszeg egész, nem negatív forintösszeg lehet.');
        }
    }

    public function wholeForints(): int
    {
        return (int) substr($this->total, 0, -3);
    }
}
