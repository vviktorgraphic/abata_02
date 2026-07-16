<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Availability\BlockedPeriodReadRepository;
use App\Application\Availability\BookingReadRepository;
use App\Application\Availability\GetAvailabilityHandler;
use App\Domain\Booking\BookingPeriod;
use App\Http\Controller\AvailabilityController;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityApiTest extends TestCase
{
    public function testValidRangeReturnsDocumentedJsonWithoutPersonalData(): void
    {
        [$status, $payload] = $this->request('2026-08-01', '2026-08-04', [
            $this->period('2026-08-02', '2026-08-04'),
        ]);

        self::assertSame(200, $status);
        self::assertSame('2026-08-01', $payload['from']);
        self::assertSame('2026-08-04', $payload['to']);
        self::assertSame('Europe/Budapest', $payload['timezone']);
        self::assertCount(3, $payload['days']);
        self::assertSame('arrival_only', $payload['days'][1]['status']);
        self::assertArrayHasKey('selectable_as_arrival', $payload['days'][0]);
        self::assertStringNotContainsString('email', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('guest', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidRangeProvider')]
    public function testInvalidRangesReturn422(string $from, string $to): void
    {
        [$status, $payload] = $this->request($from, $to);
        self::assertSame(422, $status);
        self::assertArrayHasKey('error', $payload);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRangeProvider(): iterable
    {
        yield 'invalid from' => ['2026-02-30', '2026-03-02'];
        yield 'invalid to' => ['2026-03-01', 'not-a-date'];
        yield 'same day' => ['2026-03-01', '2026-03-01'];
        yield 'reverse' => ['2026-03-02', '2026-03-01'];
        yield 'over 93 days' => ['2026-01-01', '2026-04-05'];
    }

    /** @param list<BookingPeriod> $bookings @return array{int, array<string, mixed>} */
    private function request(string $from, string $to, array $bookings = []): array
    {
        $bookingRepository = new class($bookings) implements BookingReadRepository {
            /** @param list<BookingPeriod> $periods */
            public function __construct(private readonly array $periods) {}
            public function findBlockingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array { return $this->periods; }
        };
        $blockedRepository = new class() implements BlockedPeriodReadRepository {
            public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array { return []; }
        };
        $handler = new GetAvailabilityHandler(
            $bookingRepository,
            $blockedRepository,
            today: $this->date('2026-01-01'),
        );
        http_response_code(200);
        ob_start();
        (new AvailabilityController($handler))->index(['from' => $from, 'to' => $to]);
        $body = (string) ob_get_clean();
        return [http_response_code(), json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function period(string $arrival, string $departure): BookingPeriod
    {
        return new BookingPeriod($this->date($arrival), $this->date($departure));
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

