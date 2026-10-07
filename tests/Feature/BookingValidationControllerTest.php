<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Availability\BlockedPeriodReadRepository;
use App\Application\Availability\BookingReadRepository;
use App\Application\Availability\GetAvailabilityHandler;
use App\Http\Controller\BookingValidationController;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BookingValidationControllerTest extends TestCase
{
    public function testLeadTimeIsEnforcedByLegacyValidationEndpoint(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Budapest'));

        foreach ([0, 1] as $offset) {
            [$status, $payload] = $this->request(
                $today->modify(sprintf('+%d days', $offset))->format('Y-m-d'),
                $today->modify(sprintf('+%d days', $offset + 1))->format('Y-m-d'),
                $today,
            );
            self::assertSame(422, $status);
            self::assertArrayHasKey('dates', $payload['errors']);
        }

        [$status, $payload] = $this->request(
            $today->modify('+2 days')->format('Y-m-d'),
            $today->modify('+3 days')->format('Y-m-d'),
            $today,
        );
        self::assertSame(200, $status);
        self::assertTrue($payload['valid']);
    }

    /** @return array{int, array<string, mixed>} */
    private function request(string $arrival, string $departure, DateTimeImmutable $today): array
    {
        $bookings = new class() implements BookingReadRepository {
            public function findBlockingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }
        };
        $blockedPeriods = new class() implements BlockedPeriodReadRepository {
            public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }
        };
        $input = [
            'name' => 'Teszt Vendég',
            'email' => 'guest@example.invalid',
            'phone' => '+36 30 000 0000',
            'privacy' => true,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
        ];

        http_response_code(200);
        ob_start();
        (new BookingValidationController(new GetAvailabilityHandler(
            $bookings,
            $blockedPeriods,
            today: $today,
        )))->validate($input);
        $body = (string) ob_get_clean();

        return [http_response_code(), json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }
}
