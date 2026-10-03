<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Domain\Booking\CancellationPolicy;
use App\Domain\Pricing\ChildPriceBand;
use App\Domain\Pricing\AdultStayLengthBand;
use App\Domain\Pricing\MissingChildPriceBand;
use App\Domain\Pricing\PersonPricingConfiguration;
use App\Domain\Pricing\PersonPricingNotConfigured;
use App\Domain\Pricing\PricingConfigurationError;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersonPricingEngineTest extends TestCase
{
    /** @return iterable<string,array{string,string,int,list<int>,string}> */
    public static function calculations(): iterable
    {
        yield 'one adult weekday' => ['2026-08-03','2026-08-04',1,[],'10000.00'];
        yield 'multiple adults and nights' => ['2026-08-03','2026-08-06',3,[],'90000.00'];
        yield 'Friday and Saturday' => ['2026-08-07','2026-08-09',1,[],'24000.00'];
        yield 'Thursday through Monday' => ['2026-08-06','2026-08-10',1,[],'44000.00'];
        yield 'one paid child' => ['2026-08-03','2026-08-04',1,[3],'15000.00'];
        yield 'multiple bands including free' => ['2026-08-07','2026-08-09',2,[0,6,17],'80000.00'];
        yield 'inclusive age boundaries' => ['2026-08-03','2026-08-04',1,[2,3,6,7,17],'36000.00'];
    }

    #[DataProvider('calculations')]
    public function testNightlyPersonFormula(string $arrival, string $departure, int $adults, array $ages, string $expected): void
    {
        $result = (new PricingEngine())->calculate(new PricingInput($arrival,$departure,$adults,$ages), [], null, $this->configuration());
        self::assertSame($expected, $result->accommodationFee);
        self::assertSame($expected, $result->totalAmount);
        self::assertSame('0.00', $result->tourismTax);
    }

    public function testImmutableSnapshotContainsVersionRatesAgesAndEachNight(): void
    {
        $engine = new PricingEngine();
        $result = $engine->calculate(new PricingInput('2026-08-06','2026-08-08',2,[1,5]), [], null, $this->configuration());
        $snapshot = $result->snapshot;
        self::assertSame(3, $snapshot['version']);
        self::assertSame(7, $snapshot['pricing_configuration_version']);
        self::assertSame([1,5], $snapshot['child_ages']);
        self::assertSame('10000.00', $snapshot['adult_weekday_price']);
        self::assertSame('12000.00', $snapshot['adult_weekend_price']);
        self::assertCount(2, $snapshot['nightly_breakdown']);
        self::assertFalse($snapshot['nightly_breakdown'][0]['weekend']);
        self::assertTrue($snapshot['nightly_breakdown'][1]['weekend']);
        self::assertSame('0.00', $snapshot['nightly_breakdown'][0]['children'][0]['total']);
        self::assertSame(3, $snapshot['nightly_breakdown'][0]['children'][1]['band']['min_age']);
        self::assertSame('5000.00', $snapshot['nightly_breakdown'][0]['children_total']);
        self::assertSame('55000.00', $snapshot['total']);
        self::assertSame([], $snapshot['applied_rules']);
        $engine->calculate(new PricingInput('2026-08-06','2026-08-08',2,[1,5]), [], null,
            new PersonPricingConfiguration(8,'person','1','2',$this->configuration()->childBands));
        self::assertSame($snapshot, $result->snapshot);
        self::assertSame($snapshot, json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLegacyRulesRemainAvailableButPersonModeDoesNotDoubleChargeBaseOrWeekend(): void
    {
        $rules = [new PricingRule(1,'Whole house','base',true,'2026-01-01',null,1,'100000.00','per_night'),
            new PricingRule(2,'Legacy weekend','weekend',true,'2026-01-01',null,1,'100.00',null,'percent',applicableWeekdays:[5,6])];
        $engine = new PricingEngine();
        $input = new PricingInput('2026-08-07','2026-08-08',1);
        $legacy = $engine->calculate($input,$rules,null,new PersonPricingConfiguration());
        self::assertSame('200000.00', $legacy->totalAmount);
        self::assertSame(2, $legacy->snapshot['version']);
        $person = $engine->calculate($input,$rules,null,$this->configuration());
        self::assertSame('12000.00', $person->totalAmount);
        self::assertSame([], $person->appliedRuleIds);
    }

    public function testAdultStayLengthBandOverridesWeekendForWholeStayAndIsSnapshotted(): void
    {
        $config = new PersonPricingConfiguration(9, 'person', '15000', '17000', $this->configuration()->childBands, [
            new AdultStayLengthBand(2, 2, '18000', id: 12), new AdultStayLengthBand(4, 8, '14000', id: 13),
        ]);
        $result = (new PricingEngine())->calculate(new PricingInput('2026-08-07', '2026-08-09', 2), [], null, $config);
        self::assertSame('72000.00', $result->accommodationFee);
        self::assertSame(12, $result->snapshot['adult_stay_length_band']['id']);
        self::assertSame('stay_length_band', $result->snapshot['nightly_breakdown'][0]['adult_rate_source']);
    }

    public function testAdultStayLengthBandFallsBackAndDoesNotChangeChildWeekendPricing(): void
    {
        $config = new PersonPricingConfiguration(9, 'person', '15000', '17000', $this->configuration()->childBands, [new AdultStayLengthBand(4, 8, '14000')]);
        $result = (new PricingEngine())->calculate(new PricingInput('2026-08-06', '2026-08-09', 1, [3]), [], null, $config);
        self::assertSame('66000.00', $result->accommodationFee);
        self::assertSame('weekday', $result->snapshot['nightly_breakdown'][0]['adult_rate_source']);
        self::assertSame('weekend', $result->snapshot['nightly_breakdown'][1]['adult_rate_source']);
    }

    public function testAdultStayLengthBandsRejectOverlappingActiveRanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PersonPricingConfiguration(adultStayLengthBands: [new AdultStayLengthBand(1, 4, '1'), new AdultStayLengthBand(4, 8, '1')]);
    }

    public function testSeasonalAdjustmentUsesEachActualNightAndCancellationExcludesTaxAndFixedFee(): void
    {
        $rules = [
            new PricingRule(1,'Seasonal','seasonal',true,'2026-08-07','2026-08-08',1,'10.00',null,'percent'),
            new PricingRule(2,'Fixed fee','fixed_fee',true,'2026-01-01',null,1,'2000.00'),
            new PricingRule(3,'IFA','tourism_tax',true,'2026-01-01',null,1,'500.00','per_person_per_night'),
        ];
        $result = (new PricingEngine())->calculate(new PricingInput('2026-08-06','2026-08-08',1,[5]), $rules, null, $this->configuration());
        self::assertSame('34800.00', $result->accommodationFee); // 15,000 + 18,000 + 10% of Friday 18,000.
        self::assertSame('2000.00', $result->tourismTax);
        self::assertSame('38800.00', $result->totalAmount);
        self::assertSame('2000.00', $result->snapshot['other_fees']);
        self::assertSame([1, 2, 3], array_column($result->snapshot['applied_rules'], 'id'));
        self::assertSame('10.00', $result->snapshot['applied_rules'][0]['amount']);
        self::assertSame('percent', $result->snapshot['applied_rules'][0]['adjustmentMode']);
        $policy = new CancellationPolicy();
        $late = $policy->calculate('2026-08-06', $result->snapshot['accommodation_fee'], new \DateTimeImmutable('2026-08-01T12:00:00+02:00'));
        self::assertSame('17400.00', $late->penaltyAmount);
        self::assertSame('34800.00', $late->snapshot['accommodation_fee']);
        self::assertSame('0.00', $policy->calculate('2026-08-06', $result->snapshot['accommodation_fee'], new \DateTimeImmutable('2026-07-30T23:59:00+02:00'))->penaltyAmount);
    }

    public function testConfiguredExemptionStillAppliesToSeparateTourismTax(): void
    {
        $rules = [new PricingRule(1,'IFA','tourism_tax',true,'2026-01-01',null,1,'500.00','per_person_per_night'),
            new PricingRule(2,'Owner exemption','exemption',true,'2026-01-01',null,1,'0.00',exemptionKey:'configured')];
        $result = (new PricingEngine())->calculate(new PricingInput('2026-08-03','2026-08-04',1,[],['configured']), $rules, null, $this->configuration());
        self::assertSame('0.00', $result->tourismTax);
        self::assertSame('10000.00', $result->totalAmount);
    }

    public function testMissingAgeFailsWithoutAdultOrZeroFallback(): void
    {
        $this->expectException(MissingChildPriceBand::class);
        (new PricingEngine())->calculate(new PricingInput('2026-08-03','2026-08-04',1,[7]), [], null,
            new PersonPricingConfiguration(1,'person','100','200',[new ChildPriceBand(0,6,'0','0'),new ChildPriceBand(7,17,'100','100',false)]));
    }

    public function testUnconfiguredPricesDoNotBecomeFree(): void
    {
        $this->expectException(PersonPricingNotConfigured::class);
        (new PricingEngine())->calculate(new PricingInput('2026-08-03','2026-08-04',1), [], null, new PersonPricingConfiguration(1,'person'));
    }

    public function testStorageOverflowFailsBeforeSnapshotPersistence(): void
    {
        $this->expectException(PricingConfigurationError::class);
        (new PricingEngine())->calculate(new PricingInput('2026-08-03','2026-08-04',2), [], null, new PersonPricingConfiguration(1,'person','9999999999','9999999999'));
    }

    /** @return iterable<string,array{int,int,string,string}> */
    public static function invalidBands(): iterable
    {
        yield 'negative min' => [-1,2,'0','0'];
        yield 'reversed bounds' => [7,6,'0','0'];
        yield 'adult age excluded' => [0,18,'0','0'];
        yield 'fractional price' => [0,2,'1.50','0'];
        yield 'negative price' => [0,2,'0','-1'];
        yield 'scientific notation' => [0,2,'1e3','0'];
        yield 'too large' => [0,2,'10000000000','0'];
    }

    #[DataProvider('invalidBands')]
    public function testInvalidBandsAreRejected(int $min, int $max, string $weekday, string $weekend): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChildPriceBand($min,$max,$weekday,$weekend);
    }

    public function testOverlapIncludesSharedBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PersonPricingConfiguration(childBands:[new ChildPriceBand(0,6,'0','0'),new ChildPriceBand(6,17,'1','1')]);
    }

    public function testInactiveOverlappingBandCanBeStoredButNotUsed(): void
    {
        $config = new PersonPricingConfiguration(childBands:[new ChildPriceBand(0,17,'1','1'),new ChildPriceBand(6,17,'0','0',false)]);
        self::assertSame('1.00', $config->bandForAge(6)->weekdayPrice);
    }

    private function configuration(): PersonPricingConfiguration
    {
        return new PersonPricingConfiguration(7,'person','10000','12000',[
            new ChildPriceBand(0,2,'0','0',id:1),
            new ChildPriceBand(3,6,'5000','6000',id:2),
            new ChildPriceBand(7,17,'8000','10000',id:3),
        ]);
    }
}
