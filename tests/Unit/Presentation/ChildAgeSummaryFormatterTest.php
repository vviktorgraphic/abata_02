<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\ChildAgeSummaryFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildAgeSummaryFormatterTest extends TestCase
{
    /** @param list<int> $ages */
    #[DataProvider('summaries')]
    public function testFormatsChildCountAndAgesInNaturalHungarian(int $count, array $ages, string $expected): void
    {
        self::assertSame($expected, ChildAgeSummaryFormatter::format($count, $ages));
    }

    public static function summaries(): iterable
    {
        yield 'no children' => [0, [], '0'];
        yield 'one child' => [1, [4], '1 (4 éves)'];
        yield 'two children' => [2, [4, 8], '2 (4 és 8 éves)'];
        yield 'three children' => [3, [2, 6, 11], '3 (2, 6 és 11 éves)'];
        yield 'four children' => [4, [1, 4, 8, 12], '4 (1, 4, 8 és 12 éves)'];
    }
}
