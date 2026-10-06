<?php

declare(strict_types=1);

namespace App\Presentation;

final class PercentFormatter
{
    public static function fromDecimalRate(string|int $rate): string
    {
        if (preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/', (string) $rate, $parts) !== 1) {
            throw new \InvalidArgumentException('A százalékos arány érvénytelen.');
        }

        $fraction = $parts[2] ?? '';
        $digits = $parts[1] . str_pad($fraction, 2, '0');
        $decimalPosition = strlen($parts[1]) + 2;
        $whole = ltrim(substr($digits, 0, $decimalPosition), '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = rtrim(substr($digits, $decimalPosition), '0');

        return $whole . ($decimal === '' ? '' : ',' . $decimal) . '%';
    }
}
