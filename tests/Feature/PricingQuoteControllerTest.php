<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Pricing\PricingPreviewer;
use App\Domain\Pricing\OccupancyPricingConfiguration;
use App\Domain\Pricing\OccupancyStayLengthBand;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingResult;
use App\Domain\Pricing\PricingRule;
use App\Http\Controller\PricingQuoteController;
use PHPUnit\Framework\TestCase;

final class PricingQuoteControllerTest extends TestCase
{
    public function testPublicQuoteUsesAdultOnlyIfaAndAnExactNonTaxSubtotal(): void
    {
        [$status, $payload] = $this->quote('2026-11-01', '2026-11-04');

        self::assertSame(200, $status);
        self::assertSame(4, $payload['physical_guests']);
        self::assertSame('3000.00', $payload['taxes']);
        self::assertSame('30000.00', $payload['accommodation_fee']);
        self::assertSame('32000.00', $payload['public_accommodation_total']);
        self::assertSame('35000.00', $payload['total']);
        self::assertSame(
            (int) $payload['total'],
            (int) $payload['public_accommodation_total'] + (int) $payload['taxes'],
        );
    }

    public function testOneNightSurchargeRemainsCalculatedInsidePublicAccommodationSubtotal(): void
    {
        [, $payload] = $this->quote('2026-11-01', '2026-11-02');

        self::assertSame('8000.00', $payload['one_night_surcharge']);
        self::assertSame('18000.00', $payload['accommodation_fee']);
        self::assertSame('20000.00', $payload['public_accommodation_total']);
        self::assertSame('1000.00', $payload['taxes']);
        self::assertSame('21000.00', $payload['total']);
    }

    /** @return array{int, array<string, mixed>} */
    private function quote(string $arrival, string $departure): array
    {
        $previewer = new class() implements PricingPreviewer {
            public function preview(PricingInput $input): PricingResult
            {
                $configuration = new OccupancyPricingConfiguration(
                    1,
                    '8000.00',
                    [new OccupancyStayLengthBand(1, 3, 1, null, '10000.00')],
                    [],
                    '500.00',
                );
                $rules = [new PricingRule(
                    1,
                    'Foglalási díj',
                    'fixed_fee',
                    true,
                    '2026-01-01',
                    null,
                    1,
                    '2000.00',
                    'per_booking',
                    'fixed',
                )];

                return (new PricingEngine())->calculateOccupancy($input, $configuration, $rules);
            }
        };

        http_response_code(200);
        ob_start();
        (new PricingQuoteController($previewer))->quote([
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'adults' => 2,
            'child_ages' => [2, 10],
        ]);
        $body = (string) ob_get_clean();

        return [http_response_code(), json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
}
