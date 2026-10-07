<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingOverlap;
use App\Domain\Booking\BookingOverlapPolicy;
use App\Domain\Booking\BookingPeriod;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingValidationFailed;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingDomainTest extends TestCase
{
    private BookingCreateRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BookingCreateRequestValidator(new DateTimeImmutable('2026-07-16', new DateTimeZone('Europe/Budapest')));
    }

    public function testValidRequestIsNormalizedAndCanonicalHashIsStable(): void
    {
        $first = $this->validator->validate($this->payload());
        $changedFormatting = $this->payload();
        $changedFormatting['email'] = '  TESZT@example.test ';
        $changedFormatting['phone'] = '+36 (1) 234-5678';
        $changedFormatting['idempotency_key'] = 'another-client-key-456';
        $second = $this->validator->validate($changedFormatting);

        self::assertSame('teszt@example.test', $first->email);
        self::assertSame('+3612345678', $first->phone);
        self::assertSame(3, $first->nights());
        self::assertSame($first->canonicalHash(), $second->canonicalHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->canonicalHash());
    }

    #[DataProvider('validCapacities')]
    public function testPhysicalAndChargeableCapacityAcceptsDocumentedCombinations(int $adults, array $childAges): void
    {
        $payload = $this->payload();
        $payload['adults'] = $adults;
        $payload['children'] = count($childAges);
        $payload['child_ages'] = $childAges;

        self::assertSame($adults, $this->validator->validate($payload)->adults);
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function validCapacities(): iterable
    {
        yield 'four adults' => [4, []];
        yield 'four adults and one free child' => [4, [3]];
        yield 'three adults and two free children' => [3, [2, 3]];
        yield 'three adults, one chargeable and one free child' => [3, [3, 5]];
    }

    public function testPhysicalCapacityRejectsMoreThanFivePeople(): void
    {
        $payload = $this->payload();
        $payload['adults'] = 4;
        $payload['children'] = 2;
        $payload['child_ages'] = [2, 3];

        try {
            $this->validator->validate($payload);
            self::fail('Validation should have failed.');
        } catch (BookingValidationFailed $exception) {
            self::assertSame(
                'A szállás legfeljebb 5 vendéget fogad, a gyermekeket is beleszámítva.',
                $exception->errors()['guests'],
            );
        }
    }

    public function testChargeableCapacityRejectsMoreThanFourPeople(): void
    {
        $payload = $this->payload();
        $payload['adults'] = 4;
        $payload['children'] = 1;
        $payload['child_ages'] = [4];

        try {
            $this->validator->validate($payload);
            self::fail('Validation should have failed.');
        } catch (BookingValidationFailed $exception) {
            self::assertSame(
                'Legfeljebb 4 fizető vendég foglalható; a 4 éves vagy idősebb gyermekek beleszámítanak.',
                $exception->errors()['guests'],
            );
        }
    }

    public function testTwoChargeableChildrenWithThreeAdultsAreRejected(): void
    {
        $payload = $this->payload();
        $payload['adults'] = 3;
        $payload['children'] = 2;
        $payload['child_ages'] = [5, 7];

        $this->expectException(BookingValidationFailed::class);
        $this->validator->validate($payload);
    }

    public function testSixPhysicalGuestsAreRejectedEvenWhenChildrenAreFree(): void
    {
        $payload = $this->payload();
        $payload['adults'] = 3;
        $payload['children'] = 3;
        $payload['child_ages'] = [1, 2, 3];

        $this->expectException(BookingValidationFailed::class);
        $this->validator->validate($payload);
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidBusinessInputIsRejected(string $field, mixed $value, string $expectedError): void
    {
        $payload = $this->payload();
        $payload[$field] = $value;
        try {
            $this->validator->validate($payload);
            self::fail('Validation should have failed.');
        } catch (BookingValidationFailed $exception) {
            self::assertArrayHasKey($expectedError, $exception->errors());
        }
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'invalid date' => ['arrival_date', '2026-02-30', 'arrival_date'];
        yield 'departure before arrival' => ['departure_date', '2026-08-09', 'departure_date'];
        yield 'past arrival' => ['arrival_date', '2026-07-15', 'arrival_date'];
        yield 'outside horizon' => ['arrival_date', '2027-07-17', 'arrival_date'];
        yield 'no adult' => ['adults', 0, 'adults'];
        yield 'negative children' => ['children', -1, 'children'];
        yield 'age mismatch' => ['child_ages', [], 'child_ages'];
        yield 'unreasonable age' => ['child_ages', [18], 'child_ages'];
        yield 'privacy false' => ['privacy_accepted', false, 'privacy_accepted'];
        yield 'booking policy false' => ['booking_policy_accepted', false, 'booking_policy_accepted'];
        yield 'house rules false' => ['house_rules_accepted', false, 'house_rules_accepted'];
        yield 'invalid phone' => ['phone', 'call-me', 'phone'];
        yield 'honeypot' => ['website', 'spam', 'website'];
        yield 'short key' => ['idempotency_key', 'short', 'idempotency_key'];
    }

    #[DataProvider('blockingStatuses')]
    public function testPendingAndConfirmedOverlapAreRejected(BookingStatus $status): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $requested = $this->period('2026-08-10', '2026-08-13');

        $this->expectException(BookingOverlap::class);
        $policy->assertPublicRequestAllowed($requested, [['period' => $this->period('2026-08-11', '2026-08-12'), 'status' => $status]]);
    }

    /** @return iterable<string, array{BookingStatus}> */
    public static function blockingStatuses(): iterable
    {
        yield 'pending' => [BookingStatus::Pending];
        yield 'confirmed' => [BookingStatus::Confirmed];
    }

    #[DataProvider('nonBlockingStatuses')]
    public function testClosedBookingStatusesDoNotBlockPublicRequests(BookingStatus $status): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $policy->assertPublicRequestAllowed(
            $this->period('2026-08-10', '2026-08-13'),
            [['period' => $this->period('2026-08-11', '2026-08-12'), 'status' => $status]],
        );
        self::assertTrue(true);
    }

    /** @return iterable<string, array{BookingStatus}> */
    public static function nonBlockingStatuses(): iterable
    {
        yield 'rejected' => [BookingStatus::Rejected];
        yield 'cancelled' => [BookingStatus::Cancelled];
        yield 'invalidated' => [BookingStatus::Invalidated];
    }

    public function testArrivalRequiresTwoBudapestCalendarDaysAdvanceNotice(): void
    {
        foreach (['2026-07-16', '2026-07-17'] as $arrival) {
            $payload = $this->payload();
            $payload['arrival_date'] = $arrival;
            $payload['departure_date'] = (new DateTimeImmutable($arrival))->modify('+2 days')->format('Y-m-d');
            try {
                $this->validator->validate($payload);
                self::fail('Today and tomorrow must be rejected as arrival dates.');
            } catch (BookingValidationFailed $error) {
                self::assertSame(
                    'A foglalási alapbeállítások minimum 2 nappal előre történő foglalást tesznek lehetővé. Kérem, telefonáljon, ha „Last minute” szeretne foglalni.',
                    $error->errors()['arrival_date'],
                );
            }
        }

        $payload = $this->payload();
        $payload['arrival_date'] = '2026-07-18';
        $payload['departure_date'] = '2026-07-20';
        self::assertSame('2026-07-18', $this->validator->validate($payload)->period->arrival->format('Y-m-d'));
    }

    public function testAdvanceNoticeUsesBudapestCalendarDaysAcrossDstChange(): void
    {
        $validator = new BookingCreateRequestValidator(
            new DateTimeImmutable('2026-03-28', new DateTimeZone('Europe/Budapest')),
        );
        $payload = $this->payload();
        $payload['arrival_date'] = '2026-03-30';
        $payload['departure_date'] = '2026-03-31';

        self::assertSame('2026-03-30', $validator->validate($payload)->period->arrival->format('Y-m-d'));
    }

    public function testInvalidMinimumAdvanceConfigurationFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BookingCreateRequestValidator(
            new DateTimeImmutable('2026-07-16', new DateTimeZone('Europe/Budapest')),
            minimumAdvanceDays: 1,
        );
    }

    public function testAdjacentConfirmedBookingIsAccepted(): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $policy->assertPublicRequestAllowed($this->period('2026-08-10', '2026-08-13'), [['period' => $this->period('2026-08-13', '2026-08-15'), 'status' => BookingStatus::Confirmed]]);
        self::assertTrue(true);
    }

    public function testAdjacentPendingBookingIsAccepted(): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $policy->assertPublicRequestAllowed($this->period('2026-08-10', '2026-08-13'), [['period' => $this->period('2026-08-13', '2026-08-15'), 'status' => BookingStatus::Pending]]);
        self::assertTrue(true);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['arrival_date' => '2026-08-10', 'departure_date' => '2026-08-13', 'contact_name' => ' Teszt Elek ', 'email' => 'Teszt@example.test', 'phone' => '+36 1 234 5678', 'adults' => 2, 'children' => 1, 'child_ages' => [6], 'notes' => ' Csendes ', 'privacy_accepted' => true, 'booking_policy_accepted' => true, 'house_rules_accepted' => true, 'idempotency_key' => 'client-generated-value', 'website' => ''];
    }

    private function period(string $arrival, string $departure): BookingPeriod
    {
        return new BookingPeriod(new DateTimeImmutable($arrival), new DateTimeImmutable($departure));
    }
}
