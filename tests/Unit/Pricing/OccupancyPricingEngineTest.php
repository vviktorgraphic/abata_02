<?php
declare(strict_types=1);
namespace Tests\Unit\Pricing;
use App\Domain\Pricing\{OccupancyDateOverride,OccupancyPricingConfiguration,OccupancyStayLengthBand,PricingEngine,PricingInput};
use PHPUnit\Framework\TestCase;
final class OccupancyPricingEngineTest extends TestCase
{
    private function config(array $overrides=[]): OccupancyPricingConfiguration { return new OccupancyPricingConfiguration(7,'8000.00',[new OccupancyStayLengthBand(1,1,1,null,'22000.00'),new OccupancyStayLengthBand(2,2,1,null,'27000.00'),new OccupancyStayLengthBand(3,3,1,null,'37000.00'),new OccupancyStayLengthBand(4,4,1,null,'42000.00')],$overrides); }
    public function testRoomRateIsNotMultipliedAndOneNightSurchargeIsIncluded(): void { $r=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[]),$this->config()); self::assertSame('81000.00',$r->snapshot['accommodation_fee']); self::assertSame('81000.00',$r->totalAmount); $one=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-02',2,[]),$this->config()); self::assertSame('35000.00',$one->snapshot['accommodation_fee']); }
    public function testFreeAndChargeableChildrenUseDifferentOccupancyCounts(): void { $engine=new PricingEngine(); self::assertSame(2,$engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-03',2,[2]),$this->config())->snapshot['chargeable_guests']); self::assertSame(3,$engine->calculateOccupancy(new PricingInput('2026-11-01','2026-11-03',2,[4]),$this->config())->snapshot['chargeable_guests']); }
    public function testOverrideReplacesBaseAndDepartureIsExcluded(): void { $override=new OccupancyDateOverride(9,'2026-11-02','2026-11-03',[1=>'30000',2=>'35000',3=>'45000',4=>'50000']); $r=(new PricingEngine())->calculateOccupancy(new PricingInput('2026-11-01','2026-11-04',2,[]),$this->config([$override])); self::assertSame('97000.00',$r->snapshot['accommodation_fee']); self::assertSame(['base_band','date_override','base_band'],array_column($r->snapshot['nightly_breakdown'],'source')); }
    public function testActiveOverridesCannotOverlap(): void { $this->expectException(\InvalidArgumentException::class); $this->config([new OccupancyDateOverride(1,'2026-11-01','2026-11-03',[1=>'1',2=>'1',3=>'1',4=>'1']),new OccupancyDateOverride(2,'2026-11-03','2026-11-04',[1=>'1',2=>'1',3=>'1',4=>'1'])]); }
}
