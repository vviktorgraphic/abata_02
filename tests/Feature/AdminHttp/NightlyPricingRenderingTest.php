<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NightlyPricingRenderingTest extends TestCase
{
    #[DataProvider('occupancySnapshots')]
    public function testOccupancyRenderingPreservesNightlyPricesWithoutPersonMultiplication(array $snapshot): void
    {
        $original = $snapshot;
        $view = new AdminView(dirname(__DIR__, 3) . '/templates');
        $html = $view->render('_nightly-pricing', [
            'snapshot' => $snapshot,
            'e' => static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);

        foreach (['Éjszakánkénti árbontás', 'Éjszaka', 'Árforrás', 'Éjszakai díj', '2026-12-23', 'Alapár', '27 000 Ft', '2026-12-24', '35 000 Ft'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        if (isset($snapshot['nightly_breakdown'][1]['source'])) {
            self::assertStringContainsString('Egyedi időszakos ár', $html);
        }
        foreach (['fő ×', 'Éjszakánkénti személyárak', 'Hétvége', 'Hétköznap', '99 999 Ft'] as $text) {
            self::assertStringNotContainsString($text, $html);
        }
        self::assertSame($original, $snapshot);
    }

    public static function occupancySnapshots(): iterable
    {
        $snapshot = [
            'pricing_mode' => 'occupancy',
            'nightly_breakdown' => [
                ['date' => '2026-12-23', 'source' => 'base_band', 'nightly_price' => '27000.00', 'total' => '99999.00'],
                ['date' => '2026-12-24', 'source' => 'date_override', 'nightly_price' => '35000.00', 'total' => '99999.00'],
            ],
        ];
        yield 'explicit occupancy mode' => [$snapshot];
        unset($snapshot['pricing_mode']);
        yield 'occupancy fields without mode' => [$snapshot];
        unset($snapshot['nightly_breakdown'][0]['source'], $snapshot['nightly_breakdown'][1]['source']);
        yield 'nightly price without source or mode' => [$snapshot];
    }
}
