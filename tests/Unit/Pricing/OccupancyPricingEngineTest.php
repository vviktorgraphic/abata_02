<?php
declare(strict_types=1);
namespace Tests\Unit\Pricing;
use App\Domain\Pricing\{OccupancyDateOverride,OccupancyPricingConfiguration,OccupancyStayLengthBand,OccupancyStayLengthViolation,PricingEngine,PricingInput,PricingRule};
use PHPUnit\Framework\TestCase;
final class OccupancyPricingEngineTest extends TestCase
{
    public function testConfigurationConstructorNormalizesSurcharge(): void
    {
        $configuration = new OccupancyPricingConfiguration(7, '8000.00', []);

        self::assertSame(7, $configuration->version);
        self::assertSame('8000.00', $configuration->oneNightSurcharge);
        self::assertSame('8000.00', (new OccupancyPricingConfiguration(7, '8000', []))->oneNightSurcharge);
    }

    public function testOverrideConstructorNormalizesAllGuestPrices(): void
    {
        $override = new OccupancyDateOverride(9, '2026-11-02', '2026-11-03', [1 => '30000', 2 => '35000.00', 3 => 45000, 4 => '50000']);
        $expected = [1 => '30000.00', 2 => '35000.00', 3 => '45000.00', 4 => '50000.00'];

        foreach ($expected as $guests => $price) {
            self::assertSame($price, $override->priceFor($guests));
        }
        self::assertSame($expected, $override->snapshot()['prices']);
        self::assertTrue($override->covers('2026-11-02'));
        self::assertTrue($override->covers('2026-11-03'));
        self::assertFalse($override->covers('2026-11-04'));
    }

    public function testOverrideStayLimitsAreValidatedAndSnapshotted(): void
    {
        $override = new OccupancyDateOverride(9, '2026-11-02', '2026-11-03', [1=>'1',2=>'2',3=>'3',4=>'4'], true, 3, 7);
        self::assertSame(3, $override->snapshot()['min_nights']);
        self::assertSame(7, $override->snapshot()['max_nights']);

        foreach ([[0, null], [31, null], [5, 4], [1, 31]] as [$minimum, $maximum]) {
            try {
                new OccupancyDateOverride(1, '2026-11-01', '2026-11-02', [1=>'1',2=>'2',3=>'3',4=>'4'], true, $minimum, $maximum);
                self::fail('Invalid override stay limits were accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function config(array $overrides=[], string $tax='0.00'): OccupancyPricingConfiguration { return new OccupancyPricingConfiguration(7,'8000.00',[new OccupancyStayLengthBand(1,1,1,null,'22000.00'),new OccupancyStayLengthBand(2,2,1,null,'27000.00'),new OccupancyStayLengthBand(3,3,1,null,'37000.00'),new OccupancyStayLengthBand(4,4,1,null,'42000.00')],$overrides,$tax); }
    public function testRoomRateIsNotMultipliedAndOneNightSurchargeIsIncluded(): void { $r=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[]),$this->config()); self::assertSame('81000.00',$r->snapshot['accommodation_fee']); self::assertSame('81000.00',$r->totalAmount); $one=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-02',2,[]),$this->config()); self::assertSame('35000.00',$one->snapshot['accommodation_fee']); }
    public function testFreeAndChargeableChildrenUseDifferentOccupancyCounts(): void { $engine=new PricingEngine(); self::assertSame(2,$engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-03',2,[2]),$this->config())->snapshot['chargeable_guests']); self::assertSame(3,$engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-03',2,[4]),$this->config())->snapshot['chargeable_guests']); }
    public function testOverrideReplacesBaseAndDepartureIsExcluded(): void { $override=new OccupancyDateOverride(9,'2026-11-02','2026-11-03',[1=>'30000',2=>'35000',3=>'45000',4=>'50000']); $r=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[]),$this->config([$override])); self::assertSame('97000.00',$r->snapshot['accommodation_fee']); self::assertSame(['base_band','date_override','date_override'],array_column($r->snapshot['nightly_breakdown'],'source')); }

    public function testArrivalOverrideGovernsStayLengthAndSnapshotAcrossBoundary(): void
    {
        $override = new OccupancyDateOverride(9, '2026-11-02', '2026-11-03', [1=>'30000',2=>'35000',3=>'45000',4=>'50000'], true, 3, 4);
        $result = (new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-02','2026-11-05',2), $this->config([$override]));

        self::assertSame('97000.00', $result->totalAmount);
        self::assertSame(['date_override','date_override','base_band'], array_column($result->snapshot['nightly_breakdown'], 'source'));
        self::assertSame(5, $result->snapshot['version']);
        self::assertSame(9, $result->snapshot['arrival_override_id']);
        self::assertSame(['type'=>'date_override','id'=>9,'min_nights'=>3,'max_nights'=>4], $result->snapshot['governing_stay_rule']);
    }

    public function testOverrideLimitsDoNotGovernWhenArrivalIsOutsideOverride(): void
    {
        $override = new OccupancyDateOverride(9, '2026-11-02', '2026-11-03', [1=>'30000',2=>'35000',3=>'45000',4=>'50000'], true, 5, 5);
        $result = (new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2), $this->config([$override]));

        self::assertSame('97000.00', $result->totalAmount);
        self::assertNull($result->snapshot['arrival_override_id']);
        self::assertSame('base_band', $result->snapshot['governing_stay_rule']['type']);
    }

    public function testFullyOverriddenStayDoesNotRequireBaseBand(): void
    {
        $override = new OccupancyDateOverride(9, '2026-11-01', '2026-11-03', [1=>'30000',2=>'35000',3=>'45000',4=>'50000'], true, 2, 3);
        $configuration = new OccupancyPricingConfiguration(7, '8000', [], [$override]);
        $result = (new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2), $configuration);

        self::assertSame('105000.00', $result->totalAmount);
        self::assertNull($result->snapshot['matched_base_band']);
    }

    public function testArrivalOverrideMinimumAndMaximumReturnDomainViolation(): void
    {
        $configuration = $this->config([new OccupancyDateOverride(9, '2026-11-01', '2026-11-30', [1=>'30000',2=>'35000',3=>'45000',4=>'50000'], true, 3, 4)]);
        foreach ([['2026-11-03', 'minimum 3 éjszaka'], ['2026-11-07', 'legfeljebb 4 éjszaka']] as [$departure, $message]) {
            try {
                (new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01', $departure, 2), $configuration);
                self::fail('Override stay length violation was accepted.');
            } catch (OccupancyStayLengthViolation $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }
    public function testActiveOverridesCannotOverlap(): void { $this->expectException(\InvalidArgumentException::class); $this->config([new OccupancyDateOverride(1,'2026-11-01','2026-11-03',[1=>'1',2=>'1',3=>'1',4=>'1']),new OccupancyDateOverride(2,'2026-11-03','2026-11-04',[1=>'1',2=>'1',3=>'1',4=>'1'])]); }
    public function testFixedFeeAndTourismTaxStayWholeHufInOccupancyMode(): void { $rules=[new PricingRule(1,'IFA','tourism_tax',true,'2026-01-01',null,1,'900.00','per_person_per_night'),new PricingRule(2,'Foglalási díj','fixed_fee',true,'2026-01-01',null,1,'2000.00','per_booking','fixed')]; $r=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-02',2,[]),$this->config(tax:'500'),$rules); self::assertSame('1000.00',$r->snapshot['taxes']); self::assertSame('2000.00',$r->snapshot['other_fees']); self::assertSame('38000.00',$r->totalAmount); }

    public function testConfiguredTaxUsesAdultsOnlyAndSnapshotQuantity(): void
    {
        $engine = new PricingEngine();
        $result = $engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2), $this->config(tax:'500'));
        self::assertSame('3000.00', $result->snapshot['taxes']);
        self::assertSame(6, $result->snapshot['tourism_tax_quantity']);
        self::assertSame('500.00', $result->snapshot['tourism_tax_per_person_per_night']);
        $child = $engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[2]), $this->config(tax:'500'));
        self::assertSame('3000.00', $child->snapshot['taxes']);
        self::assertSame(6, $child->snapshot['tourism_tax_quantity']);
        self::assertSame(2, $child->snapshot['chargeable_guests']);
    }

    public function testZeroTaxAndApplicableExemptionSuppressTaxWithoutUsingLegacyTaxAmount(): void
    {
        $engine = new PricingEngine();
        $legacy = new PricingRule(1,'Legacy IFA','tourism_tax',true,'2026-01-01',null,1,'900.00','per_person_per_night');
        self::assertSame('0.00', $engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2),$this->config(),[$legacy])->snapshot['taxes']);
        $exemption = new PricingRule(2,'Configured exemption','exemption',true,'2026-01-01',null,1,'0.00',exemptionKey:'existing');
        $result = $engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[],['existing']),$this->config(tax:'500'),[$legacy,$exemption]);
        self::assertSame('0.00', $result->snapshot['taxes']);
        self::assertSame(0, $result->snapshot['tourism_tax_quantity']);
        self::assertSame('500.00', $result->snapshot['tourism_tax_per_person_per_night']);
        self::assertSame('3000.00', $engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2),$this->config(tax:'500'),[$exemption])->snapshot['taxes']);
    }

    public function testOverlappingBaseBandMessageExplainsRequiredRemediation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Előbb módosítsa vagy inaktiválja a meglévő ársávot.');
        new OccupancyPricingConfiguration(1,'0',[new OccupancyStayLengthBand(1,1,1,null,'22000'),new OccupancyStayLengthBand(2,1,2,7,'22000')]);
    }

    public function testFullYear2027OverrideCoversAllOccupanciesAndInclusiveLastNight(): void
    {
        $prices = [1=>'23000',2=>'28000',3=>'38000',4=>'43000'];
        $config = $this->config([new OccupancyDateOverride(1,'2027-01-01','2027-12-31',$prices)]);
        foreach ($prices as $guests => $price) {
            $result = (new PricingEngine())->calculateOccupancy(new PricingInput('2027-12-30','2028-01-01',$guests),$config);
            self::assertSame(((int)$price*2).'.00',$result->totalAmount);
            self::assertSame(['date_override','date_override'],array_column($result->snapshot['nightly_breakdown'],'source'));
        }
    }
}
