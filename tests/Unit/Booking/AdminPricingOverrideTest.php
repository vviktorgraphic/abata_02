<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Booking\AdminCustomPrice;
use App\Application\Booking\AdminCustomPriceInvalid;
use App\Application\Booking\AdminPricingOverride;
use App\Domain\Pricing\PricingResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminPricingOverrideTest extends TestCase
{
    #[DataProvider('customTotals')]
    public function testAppliesHigherLowerAndTaxOnlyCustomTotals(
        string $input,
        string $expectedTotal,
        string $expectedAccommodation,
    ): void {
        $calculated = $this->calculated();
        $effective = (new AdminPricingOverride())->applyToResult(
            $calculated,
            AdminCustomPrice::optionalFromInput($input),
        );

        self::assertSame($expectedTotal, $effective->totalAmount);
        self::assertSame($expectedAccommodation, $effective->accommodationFee);
        self::assertSame('500.00', $effective->tourismTax);
        self::assertSame($expectedTotal, $effective->snapshot['total']);
        self::assertSame($expectedAccommodation, $effective->snapshot['accommodation_fee']);
        self::assertSame('500.00', $effective->snapshot['taxes']);
        self::assertSame([
            'type' => 'admin_custom_total',
            'calculated_accommodation_fee' => '30000.00',
            'calculated_tourism_tax' => '500.00',
            'calculated_total' => '30500.00',
            'custom_accommodation_fee' => $expectedAccommodation,
            'custom_total' => $expectedTotal,
        ], $effective->snapshot['price_override']);
        self::assertSame('30500.00', $calculated->snapshot['total']);
        self::assertArrayNotHasKey('price_override', $calculated->snapshot);
    }

    public static function customTotals(): iterable
    {
        yield 'lower with grouped input' => ['28 500', '28500.00', '28000.00'];
        yield 'higher' => ['40500', '40500.00', '40000.00'];
        yield 'equal to IFA' => ['500', '500.00', '0.00'];
    }

    public function testEmptyInputLeavesCalculatorResultAndSnapshotExactlyUntouched(): void
    {
        $calculated = $this->calculated();
        $effective = (new AdminPricingOverride())->applyToResult(
            $calculated,
            AdminCustomPrice::optionalFromInput(''),
        );

        self::assertSame($calculated, $effective);
        self::assertArrayNotHasKey('price_override', $effective->snapshot);
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidNonIntegerOrOutOfRangeInput(mixed $input): void
    {
        $this->expectException(AdminCustomPriceInvalid::class);
        AdminCustomPrice::optionalFromInput($input);
    }

    public static function invalidInputs(): iterable
    {
        yield 'decimal' => ['28500.50'];
        yield 'negative' => ['-1'];
        yield 'too large' => ['10000000000'];
        yield 'not numeric' => ['egyedi'];
        yield 'array' => [[]];
    }

    public function testRejectsCustomTotalBelowCalculatedTourismTaxWithRequiredMessage(): void
    {
        $this->expectException(AdminCustomPriceInvalid::class);
        $this->expectExceptionMessage('Az egyedi végösszeg nem lehet alacsonyabb az IFA összegénél.');
        (new AdminPricingOverride())->applyToResult($this->calculated(), AdminCustomPrice::fromCanonical('499.00'));
    }

    private function calculated(): PricingResult
    {
        return new PricingResult(
            '30500.00',
            '30000.00',
            '500.00',
            'HUF',
            [['type' => 'accommodation', 'total' => '30000.00']],
            [1],
            [
                'version' => 5,
                'accommodation_fee' => '30000.00',
                'taxes' => '500.00',
                'total' => '30500.00',
                'currency' => 'HUF',
            ],
        );
    }
}
