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
        yield 'invalid phone' => ['phone', 'call-me', 'phone'];
        yield 'honeypot' => ['website', 'spam', 'website'];
        yield 'short key' => ['idempotency_key', 'short', 'idempotency_key'];
    }

    public function testPendingOverlapIsAcceptedButConfirmedOverlapIsRejected(): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $requested = $this->period('2026-08-10', '2026-08-13');
        $policy->assertPublicRequestAllowed($requested, [['period' => $this->period('2026-08-11', '2026-08-12'), 'status' => BookingStatus::Pending]]);

        $this->expectException(BookingOverlap::class);
        $policy->assertPublicRequestAllowed($requested, [['period' => $this->period('2026-08-12', '2026-08-14'), 'status' => BookingStatus::Confirmed]]);
    }

    public function testAdjacentConfirmedBookingIsAccepted(): void
    {
        $policy = new BookingOverlapPolicy(new AvailabilityService(new DateTimeImmutable('2026-07-16')));
        $policy->assertPublicRequestAllowed($this->period('2026-08-10', '2026-08-13'), [['period' => $this->period('2026-08-13', '2026-08-15'), 'status' => BookingStatus::Confirmed]]);
        self::assertTrue(true);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['arrival_date' => '2026-08-10', 'departure_date' => '2026-08-13', 'contact_name' => ' Teszt Elek ', 'email' => 'Teszt@example.test', 'phone' => '+36 1 234 5678', 'adults' => 2, 'children' => 1, 'child_ages' => [6], 'notes' => ' Csendes ', 'privacy_accepted' => true, 'idempotency_key' => 'client-generated-value', 'website' => ''];
    }

    private function period(string $arrival, string $departure): BookingPeriod
    {
        return new BookingPeriod(new DateTimeImmutable($arrival), new DateTimeImmutable($departure));
    }
}
