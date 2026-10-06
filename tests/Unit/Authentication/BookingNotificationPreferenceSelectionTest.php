<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Application\Authentication\BookingNotificationPreferenceSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingNotificationPreferenceSelectionTest extends TestCase
{
    public function test_missing_is_empty_and_valid_ids_are_deduplicated(): void
    {
        self::assertSame([], BookingNotificationPreferenceSelection::fromForm(null));
        self::assertSame([2, 5], BookingNotificationPreferenceSelection::fromForm(['2', 5, '2']));
    }

    #[DataProvider('malformedSelections')]
    public function test_malformed_selections_are_rejected(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BookingNotificationPreferenceSelection::fromForm($value);
    }

    public static function malformedSelections(): iterable
    {
        yield 'scalar' => ['1'];
        yield 'zero' => [['0']];
        yield 'negative' => [['-1']];
        yield 'decimal' => [['1.5']];
        yield 'mixed text' => [['1x']];
        yield 'nested value' => [[['1']]];
    }
}
