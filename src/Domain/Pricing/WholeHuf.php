<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class WholeHuf
{
    /** Parse whole HUF admin input without locale or floating-point conversion. */
    public static function fromInput(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,9}|[1-9][0-9]{0,2}(?:[ \x{00A0}][0-9]{3}){1,3})(?:\.00)?$/uD', $amount) !== 1) {
            throw new \InvalidArgumentException('Egész, nem negatív forintár szükséges.');
        }
        return self::normalize(str_replace([' ', "\u{00A0}"], '', $amount));
    }

    public static function normalize(string $amount): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.00)?$/D', $amount, $matches) !== 1) {
            throw new \InvalidArgumentException('Az ár nem negatív, egész forint lehet (legfeljebb 9 999 999 999 Ft).');
        }
        return $matches[1].'.00';
    }
}
