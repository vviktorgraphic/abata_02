<?php
declare(strict_types=1);
namespace Tests\Unit\Booking;

use App\Application\Booking\BookingModificationPreviewSigner;
use App\Application\Booking\ConfirmedBookingModificationValidator;
use App\Domain\Booking\BookingValidationFailed;
use PHPUnit\Framework\TestCase;

final class ConfirmedBookingModificationValidatorTest extends TestCase
{
    public function testAllowsAdminArrivalTomorrowWithoutPublicTwoDayAdvanceRule(): void
    {
        $request = $this->validator()->validate($this->valid());
        self::assertSame('2026-10-10', $request->arrivalDate);
        self::assertSame([3], $request->childAges);
    }

    public function testRejectsInvalidOrderAndMoreThanThirtyNights(): void
    {
        foreach ([
            ['arrival_date'=>'2026-10-12','departure_date'=>'2026-10-12'],
            ['arrival_date'=>'2026-10-10','departure_date'=>'2026-11-10'],
        ] as $change) {
            try { $this->validator()->validate($change + $this->valid()); self::fail('Validation failure expected.'); }
            catch (BookingValidationFailed $error) { self::assertArrayHasKey('departure_date', $error->errors()); }
        }
    }

    public function testRejectsCapacityAndMissingOrInvalidChildAges(): void
    {
        foreach ([
            ['adults'=>'4','children'=>'2','child_ages'=>['0','1']],
            ['adults'=>'3','children'=>'2','child_ages'=>['4','17']],
            ['children'=>'2','child_ages'=>['3']],
            ['children'=>'1','child_ages'=>['18']],
        ] as $change) {
            try { $this->validator()->validate(array_replace($this->valid(), $change)); self::fail('Validation failure expected.'); }
            catch (BookingValidationFailed $error) { self::assertNotSame([], $error->errors()); }
        }
    }

    public function testRejectsDatesOutsideHorizonButDoesNotInventPastDateRule(): void
    {
        $past = $this->validator()->validate(array_replace($this->valid(), ['arrival_date'=>'2026-10-01','departure_date'=>'2026-10-02']));
        self::assertSame('2026-10-01', $past->arrivalDate);
        $this->expectException(BookingValidationFailed::class);
        $this->validator()->validate(array_replace($this->valid(), ['arrival_date'=>'2027-10-10','departure_date'=>'2027-10-11']));
    }

    public function testPreviewSignatureBindsReferenceVersionAndFields(): void
    {
        $request = $this->validator()->validate($this->valid());
        $signer = new BookingModificationPreviewSigner('test-secret');
        $hash = str_repeat('a', 64);
        $signature = $signer->sign('AB-1', 2, $request, $hash);
        self::assertTrue($signer->verify($signature, 'AB-1', 2, $request, $hash));
        self::assertFalse($signer->verify($signature, 'AB-1', 3, $request, $hash));
    }

    /** @return array<string,mixed> */
    private function valid(): array
    {
        return ['arrival_date'=>'2026-10-10','departure_date'=>'2026-10-12','adults'=>'2','children'=>'1','child_ages'=>['3']];
    }

    private function validator(): ConfirmedBookingModificationValidator
    {
        return new ConfirmedBookingModificationValidator(new \DateTimeImmutable('2026-10-09', new \DateTimeZone('Europe/Budapest')));
    }
}
