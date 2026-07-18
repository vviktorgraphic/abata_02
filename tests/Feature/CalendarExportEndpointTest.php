<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Calendar\CalendarExportFeed;
use App\Application\Calendar\CalendarExportFeedRepository;
use App\Application\Calendar\CalendarExportTokenRepository;
use App\Application\Calendar\IcalExporter;
use App\Domain\Calendar\IcalExportEvent;
use App\Http\Controller\CalendarExportController;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarExportEndpointTest extends TestCase
{
    #[DataProvider('invalidTokenProvider')]
    public function testMissingOrInvalidTokenReturnsIndistinguishableNotFoundResponse(array $query): void
    {
        $response = $this->controller()->export($query);

        self::assertSame(404, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('private, no-store, max-age=0', $response->headers['Cache-Control']);
        self::assertSame('no-referrer', $response->headers['Referrer-Policy']);
    }

    public static function invalidTokenProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'invalid' => [['token' => 'wrong']];
        yield 'non scalar' => [['token' => ['secret']]];
    }

    public function testValidTokenReturnsPrivateUtf8InlineCalendarWithoutPersonalData(): void
    {
        $response = $this->controller()->export(['token' => 'valid-secret']);

        self::assertSame(200, $response->status);
        self::assertSame('text/calendar; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('inline; filename="calendar.ics"', $response->headers['Content-Disposition']);
        self::assertSame('private, no-store, max-age=0', $response->headers['Cache-Control']);
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $response->body);
        self::assertStringContainsString('UID:opaque-public-uid@example.invalid', $response->body);
        self::assertStringNotContainsString('guest', strtolower($response->body));
        self::assertStringNotContainsString('valid-secret', $response->body);
    }

    private function controller(): CalendarExportController
    {
        $tokens = new class implements CalendarExportTokenRepository {
            public function rotate(string $plainToken, DateTimeImmutable $at): void {}
            public function verify(string $plainToken): bool { return hash_equals('valid-secret', $plainToken); }
            public function metadata(): ?array { return null; }
        };
        $events = new class implements CalendarExportFeedRepository {
            public function exportableEvents(): array
            {
                $timezone = new DateTimeZone('Europe/Budapest');
                return [new IcalExportEvent(
                    'opaque-public-uid@example.invalid',
                    new DateTimeImmutable('2027-04-01 00:00:00', $timezone),
                    new DateTimeImmutable('2027-04-03 00:00:00', $timezone),
                    new DateTimeImmutable('2027-03-01 12:00:00', $timezone),
                )];
            }
        };

        return new CalendarExportController($tokens, new CalendarExportFeed($events, new IcalExporter()));
    }
}
