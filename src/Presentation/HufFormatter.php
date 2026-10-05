<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Presentation only: never writes back to immutable monetary snapshots. */
final class HufFormatter
{
    public static function format(string|int $amount): string
    {
        return self::groupedInput($amount) . ' Ft';
    }

    /** Human-readable input value; existing ungrouped input() remains compatible. */
    public static function groupedInput(string|int $amount): string
    {
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', self::input($amount));
    }

    /** Whole, ungrouped HUF using decimal HALF_UP without floats or integer overflow. */
    public static function input(string|int $amount): string
    {
        if (preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/', (string) $amount, $parts) !== 1) {
            throw new InvalidArgumentException('HUF amount must be a decimal string or integer.');
        }
        $whole = ltrim($parts[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (isset($parts[3]) && $parts[3][0] >= '5') {
            for ($index = strlen($whole) - 1; $index >= 0; --$index) {
                if ($whole[$index] !== '9') {
                    $whole[$index] = (string) ((int) $whole[$index] + 1);
                    break;
                }
                $whole[$index] = '0';
            }
            if ($index < 0) {
                $whole = '1' . $whole;
            }
        }
        return ($parts[1] === '-' && $whole !== '0' ? '-' : '') . $whole;
    }
}
