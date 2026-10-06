<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\PercentFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentFormatterTest extends TestCase
{
    #[DataProvider('rates')]
    public function test_decimal_rates_are_formatted_as_hungarian_percentages(string $rate, string $expected): void
    {
        self::assertSame($expected, PercentFormatter::fromDecimalRate($rate));
    }

    public static function rates(): iterable
    {
        yield 'zero' => ['0.0000', '0%'];
        yield 'half' => ['0.5000', '50%'];
        yield 'whole' => ['1.0000', '100%'];
        yield 'fractional percentage' => ['0.1250', '12,5%'];
    }
}
