<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Domain\Pricing\OccupancyDateOverride;
use App\Domain\Pricing\OccupancyPricingConfiguration;
use App\Domain\Pricing\OccupancyStayLengthBand;
use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class OccupancyPricingRenderingTest extends TestCase
{
    public function testWholeHufFieldsAndYearPricingGuidanceAreRendered(): void
    {
        $html = $this->render();
        foreach (['22 000 Ft', '27 000 Ft', '42 000 Ft', 'value="22 000"', 'value="8 000"', 'value="500"', 'Idegenforgalmi adó (IFA)', 'IFA (Ft / fő / éj)', 'dátumtól függetlenül', 'Éves vagy szezonális ár beállítása', 'href="#override-prices-title"', 'id="override-prices-title"', 'Korlátlan', 'Hagyja üresen, ha nincs felső korlát.'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('.00', $html);
    }

    public function testEditFormsSpanTheTableAndKeepActivationOutsideTheEditor(): void
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $this->render());
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//tr[@class="pricing-edit-row"]/td[@colspan="6"]/details/form')->length);
        self::assertSame(1, $xpath->query('//tr[@class="pricing-edit-row"]/td[@colspan="7"]/details/form')->length);
        self::assertSame(0, $xpath->query('//details[@class="pricing-editor"]//input[@value="band_toggle" or @value="override_toggle"]')->length);
        foreach (['band', 'override', 'tourism_tax', 'surcharge', 'band_toggle', 'override_toggle'] as $action) {
            $forms = $xpath->query('//form[input[@name="action" and @value="' . $action . '"]]');
            self::assertGreaterThan(0, $forms->length);
            foreach ($forms as $form) {
                self::assertSame(1, $xpath->query('input[@name="_csrf" and @value="csrf-example"]', $form)->length);
                self::assertSame(1, $xpath->query('input[@name="version" and @value="7"]', $form)->length);
            }
        }
    }

    private function render(): string
    {
        $configuration = new OccupancyPricingConfiguration(
            version: 7,
            oneNightSurcharge: '8000.00',
            bands: [new OccupancyStayLengthBand(1, 1, 1, null, '22000.00')],
            overrides: [new OccupancyDateOverride(2, '2027-01-01', '2027-12-31', [1 => '22000.00', 2 => '27000.00', 3 => '35000.00', 4 => '42000.00'])],
            tourismTaxPerPersonPerNight: '500.00',
        );
        return (new AdminView(dirname(__DIR__, 3) . '/templates'))->render('occupancy-pricing', [
            'configuration' => $configuration,
            'csrfToken' => 'csrf-example',
            'error' => null,
        ]);
    }
}
