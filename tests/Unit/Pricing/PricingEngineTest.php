<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\PricingConfigurationError;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void { $this->engine = new PricingEngine(); }

    /** @return iterable<string,array{int,string,string}> */
    public static function stayBands(): iterable
    {
        yield 'one' => [1, '10000.00', '10000.00']; yield 'two' => [2, '9000.00', '18000.00'];
        yield 'three' => [3, '8000.00', '24000.00']; yield 'four' => [4, '7000.00', '28000.00'];
        yield 'six' => [6, '7000.00', '42000.00']; yield 'seven' => [7, '6000.00', '42000.00'];
    }

    #[DataProvider('stayBands')]
    public function testStayLengthBands(int $nights, string $amount, string $expected): void
    {
        $rules = [
            $this->rule(1, 'stay_length', '10000.00', 'per_night', min: 1, max: 1),
            $this->rule(2, 'stay_length', '9000.00', 'per_night', min: 2, max: 2),
            $this->rule(3, 'stay_length', '8000.00', 'per_night', min: 3, max: 3),
            $this->rule(4, 'stay_length', '7000.00', 'per_night', min: 4, max: 6),
            $this->rule(5, 'stay_length', '6000.00', 'per_night', min: 7),
        ];
        self::assertSame($expected, $this->engine->calculate(new PricingInput('2026-08-01', (new \DateTimeImmutable('2026-08-01'))->modify("+$nights days")->format('Y-m-d'), 1), $rules)->totalAmount);
        self::assertSame($amount, array_values(array_filter($rules, static fn (PricingRule $r): bool => $r->minimumNights <= $nights && ($r->maximumNights === null || $r->maximumNights >= $nights)))[0]->amount);
    }

    /** @return iterable<string,array{string,string}> */
    public static function baseUnits(): iterable { yield 'person night' => ['per_person_per_night','60000.00']; yield 'night' => ['per_night','20000.00']; yield 'booking' => ['per_booking','10000.00']; }
    #[DataProvider('baseUnits')]
    public function testAllBaseUnits(string $unit, string $expected): void
    {
        $result = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-03',2,[4]), [$this->rule(1,'base','10000.00',$unit)]);
        self::assertSame($expected, $result->totalAmount);
    }

    public function testSeasonAndExplicitWeekendAdjustmentsAreAppliedInOrder(): void
    {
        $rules = [$this->rule(1,'base','10000.00','per_night'), $this->rule(2,'seasonal','10.00',mode:'percent'), $this->rule(3,'weekend','20.00',mode:'percent', weekdays:[6])];
        $result = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-03',1), $rules);
        self::assertSame('24200.00', $result->totalAmount); // 20,000 +10%; then one of two nights +20% of 22,000/2.
        self::assertSame([1,2,3], $result->appliedRuleIds);
    }

    public function testWinningPriorityAndEqualPriorityConflict(): void
    {
        $low = $this->rule(1,'base','100.00','per_booking',priority: 1); $high = $this->rule(2,'base','200.00','per_booking',priority: 2);
        self::assertSame('200.00', $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [$low,$high])->totalAmount);
        $this->expectException(PricingConfigurationError::class);
        $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [$low,$this->rule(3,'base','300.00','per_booking',priority: 1)]);
    }

    public function testMissingWeekendDayConfigurationFailsExplicitly(): void
    {
        $this->expectException(PricingConfigurationError::class);
        $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [$this->rule(1,'base','100.00','per_booking'),$this->rule(2,'weekend','10.00',mode:'percent')]);
    }

    public function testFixedFeeTaxAndConfiguredExemption(): void
    {
        $rules = [$this->rule(1,'base','1000.00','per_night'),$this->rule(2,'fixed_fee','500.00'),$this->rule(3,'tourism_tax','100.00','per_person_per_night'),$this->rule(4,'exemption','0.00',exemption:'owner-configured-key')];
        $normal = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-03',2), $rules);
        self::assertSame('400.00', $normal->tourismTax); self::assertSame('2000.00', $normal->accommodationFee); self::assertSame('2900.00', $normal->totalAmount);
        $exempt = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-03',2,[],['owner-configured-key']), $rules);
        self::assertSame('0.00', $exempt->tourismTax); self::assertSame('2500.00', $exempt->totalAmount);
    }

    public function testEqualPriorityFixedFeesFailInsteadOfBeingSilentlyCombined(): void
    {
        $this->expectException(PricingConfigurationError::class);
        $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [
            $this->rule(1,'base','1000.00','per_booking'),
            $this->rule(2,'fixed_fee','100.00',priority: 4),
            $this->rule(3,'fixed_fee','200.00',priority: 4),
        ]);
    }

    public function testDisjointEqualPriorityFixedFeesAreAllowed(): void
    {
        $first = new PricingRule(2,'First fee','fixed_fee',true,'2026-08-01','2026-08-02',4,'100.00');
        $second = new PricingRule(3,'Second fee','fixed_fee',true,'2026-08-02','2026-08-03',4,'200.00');
        $result = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-03',1), [
            $this->rule(1,'base','1000.00','per_booking'), $first, $second,
        ]);
        self::assertSame('1300.00', $result->totalAmount);
    }

    public function testEqualPriorityMatchingExemptionsFailExplicitly(): void
    {
        $this->expectException(PricingConfigurationError::class);
        $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1,[],['first','second']), [
            $this->rule(1,'base','1000.00','per_booking'),
            $this->rule(2,'tourism_tax','100.00','per_booking'),
            $this->rule(3,'exemption','0.00',priority: 2,exemption:'first'),
            $this->rule(4,'exemption','0.00',priority: 2,exemption:'second'),
        ]);
    }

    public function testHalfUpRoundingAndSnapshotIsAValueCopy(): void
    {
        $rule = $this->rule(1,'base','10.50','per_booking');
        $result = $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [$rule], new \DateTimeImmutable('2026-07-16T12:00:00+02:00'));
        self::assertSame('11.00', $result->totalAmount); self::assertSame('HALF_UP', $result->snapshot['rounding']['mode']);
        $changedRule = $this->rule(1,'base','99.00','per_booking');
        self::assertSame('11.00', $result->snapshot['total']);
        self::assertSame('99.00', $this->engine->calculate(new PricingInput('2026-08-01','2026-08-02',1), [$changedRule])->totalAmount);
    }

    /** @param list<int> $weekdays */
    private function rule(int $id, string $type, string $amount, ?string $unit = null, ?string $mode = null, ?int $min = null, ?int $max = null, int $priority = 1, array $weekdays = [], ?string $exemption = null): PricingRule
    {
        return new PricingRule($id,"Rule $id",$type,true,'2026-01-01',null,$priority,$amount,$unit,$mode,$min,$max,$exemption,$weekdays);
    }
}
