<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingValidationFailed;
use App\Domain\Booking\GuestCapacityPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminManualBookingValidatorTest extends TestCase
{
    public function testTodayArrivalAndMissingPublicLegalAcceptancesAreAllowed(): void
    {
        $request = $this->validator()->validate($this->validPayload());

        self::assertSame('2047-06-10', $request->period->arrival->format('Y-m-d'));
        self::assertFalse($request->privacyAccepted);
        self::assertFalse($request->bookingPolicyAccepted);
        self::assertFalse($request->houseRulesAccepted);
        self::assertSame('+36301234567', $request->phone);
    }

    public function testTomorrowArrivalIsAllowedWithoutPublicAdvanceRule(): void
    {
        $request = $this->validator()->validate(array_replace($this->validPayload(), [
            'arrival_date' => '2047-06-11',
            'departure_date' => '2047-06-13',
        ]));

        self::assertSame('2047-06-11', $request->period->arrival->format('Y-m-d'));
    }

    #[DataProvider('invalidPayloads')]
    public function testAdminInputStillEnforcesSharedValidationRules(array $changes, string $errorKey): void
    {
        try {
            $this->validator()->validate(array_replace($this->validPayload(), $changes));
            self::fail('Invalid admin booking payload was accepted.');
        } catch (BookingValidationFailed $error) {
            self::assertArrayHasKey($errorKey, $error->errors());
        }
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'past arrival' => [['arrival_date' => '2047-06-09'], 'arrival_date'];
        yield 'departure not after arrival' => [['departure_date' => '2047-06-10'], 'departure_date'];
        yield 'minimum stay' => [['departure_date' => '2047-06-11'], 'departure_date'];
        yield 'maximum stay' => [['departure_date' => '2047-06-16'], 'departure_date'];
        yield 'arrival after horizon' => [['arrival_date' => '2047-07-01', 'departure_date' => '2047-07-03'], 'arrival_date'];
        yield 'departure after horizon' => [['arrival_date' => '2047-06-18', 'departure_date' => '2047-06-21'], 'departure_date'];
        yield 'invalid name' => [['contact_name' => ''], 'contact_name'];
        yield 'invalid email' => [['email' => 'not-an-email'], 'email'];
        yield 'invalid phone' => [['phone' => 'abc'], 'phone'];
        yield 'adult limit' => [['adults' => '9'], 'adults'];
        yield 'child count mismatch' => [['children' => '2'], 'child_ages'];
        yield 'child age range' => [['child_ages' => ['18']], 'child_ages'];
        yield 'physical capacity' => [['adults' => '8', 'children' => '1', 'child_ages' => ['3']], 'guests'];
        yield 'notes length' => [['notes' => '12345678901'], 'notes'];
        yield 'idempotency key' => [['idempotency_key' => 'short'], 'idempotency_key'];
    }

    /** @return array<string,mixed> */
    private function validPayload(): array
    {
        return [
            'arrival_date' => '2047-06-10',
            'departure_date' => '2047-06-12',
            'contact_name' => 'Teszt Vendég',
            'email' => 'guest@example.invalid',
            'phone' => '+36 30 123 4567',
            'adults' => '2',
            'children' => '1',
            'child_ages' => ['3'],
            'notes' => 'Megjegyzés',
            'idempotency_key' => 'admin-test-key-123456',
        ];
    }

    private function validator(): BookingCreateRequestValidator
    {
        return new BookingCreateRequestValidator(
            new DateTimeImmutable('2047-06-10', new DateTimeZone('Europe/Budapest')),
            minimumNights: 2,
            maximumNights: 5,
            bookingHorizonDays: 10,
            minimumAdvanceDays: 0,
            maximumAdults: 8,
            maximumChildren: 8,
            maximumNotesLength: 10,
            capacityPolicy: new GuestCapacityPolicy(),
            requireLegalAcceptances: false,
        );
    }
}
