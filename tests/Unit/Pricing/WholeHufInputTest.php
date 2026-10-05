<?php
declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\WholeHuf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WholeHufInputTest extends TestCase
{
    public static function valid(): array
    {
        return [['22000','22000.00'],['22 000','22000.00'],["22\u{00A0}000",'22000.00'],['22000.00','22000.00'],['8000','8000.00'],['0','0.00'],['9 999 999 999','9999999999.00']];
    }
    #[DataProvider('valid')]
    public function testNormalizesWholeHuf(string $input,string $expected): void
    {
        self::assertSame($expected,WholeHuf::fromInput($input));
    }
    public static function invalid(): array
    {
        return array_map(static fn(string $input): array=>[$input],['22.50','-100','1e4','22,000','letters','22 00','22 000x','10000000000','01','22\t000']);
    }
    #[DataProvider('invalid')]
    public function testRejectsMalformedOrFractionalHuf(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WholeHuf::fromInput($input);
    }
}
