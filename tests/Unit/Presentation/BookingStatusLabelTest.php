<?php
declare(strict_types=1);
namespace Tests\Unit\Presentation;
use App\Presentation\BookingStatusLabel;
use PHPUnit\Framework\TestCase;
final class BookingStatusLabelTest extends TestCase
{
    public function testKnownStatusesAreHungarianAndUnknownFallsBack(): void
    {
        self::assertSame(['Függőben','Megerősítve','Elutasítva','Lemondva','Érvénytelenítve'], array_map([BookingStatusLabel::class, 'for'], ['pending','confirmed','rejected','cancelled','invalidated']));
        self::assertSame('future_status', BookingStatusLabel::for('future_status'));
    }
}
