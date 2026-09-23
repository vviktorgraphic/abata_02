<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\HufFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HufFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testFormatsDecimalAmountsWithoutFloatArithmetic(string|int $amount, string $expected, string $input): void
    {
        self::assertSame($expected, HufFormatter::format($amount));
        self::assertSame($input, HufFormatter::input($amount));
    }

    public static function amounts(): iterable
    {
        yield ['20000.00', '20 000 Ft', '20000'];
        yield [0, '0 Ft', '0'];
        yield ['-0.49', '0 Ft', '0'];
        yield ['00000.00', '0 Ft', '0'];
        yield ['-0.50', '-1 Ft', '-1'];
        yield ['-20000.50', '-20 001 Ft', '-20001'];
        yield ['999.50', '1 000 Ft', '1000'];
        yield ['19999.499', '19 999 Ft', '19999'];
        yield ['999999999999999999999999.50', '1 000 000 000 000 000 000 000 000 Ft', '1000000000000000000000000'];
        yield [PHP_INT_MIN, '-9 223 372 036 854 775 808 Ft', '-9223372036854775808'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsAmbiguousOrNonDecimalValues(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HufFormatter::format($amount);
    }

    public static function invalidAmounts(): iterable
    {
        foreach (['', '1e4', '1,50', '1 000', 'NaN', 'INF', "10\n", '<script>'] as $amount) {
            yield [$amount];
        }
    }
}
