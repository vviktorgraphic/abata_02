<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class WholeHuf
{
    public static function normalize(string $amount): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.00)?$/D', $amount, $matches) !== 1) {
            throw new \InvalidArgumentException('Az ár nem negatív, egész forint lehet (legfeljebb 9 999 999 999 Ft).');
        }
        return $matches[1].'.00';
    }
}
