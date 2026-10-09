<?php
declare(strict_types=1);
namespace Tests\Unit\Booking;
use App\Domain\Booking\GuestCapacityPolicy;
use PHPUnit\Framework\TestCase;

final class GuestCapacityPolicyTest extends TestCase
{
    public function testSharedPolicyCoversPhysicalAndChargeableCapacity(): void
    {
        $policy=new GuestCapacityPolicy();
        self::assertNull($policy->violation(2,[0,3,4]));
        self::assertStringContainsString('legfeljebb 5 vendéget',$policy->violation(4,[0,1]));
        self::assertStringContainsString('Legfeljebb 4 fizető vendég',$policy->violation(3,[4,17]));
    }
}
