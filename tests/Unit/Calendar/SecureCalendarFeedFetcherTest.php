<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Application\Calendar\CalendarFeedFetchException;
use App\Application\Calendar\CalendarFeedHttpClient;
use App\Application\Calendar\CalendarFeedResponse;
use App\Application\Calendar\CalendarHostResolver;
use App\Application\Calendar\SecureCalendarFeedFetcher;
use PHPUnit\Framework\TestCase;

final class SecureCalendarFeedFetcherTest extends TestCase
{
    public function testFetchesPinnedHttpsFeedWithinLimits(): void
    {
        $client = new FakeFeedClient(new CalendarFeedResponse(200, 'BEGIN:VCALENDAR'));
        $fetcher = new SecureCalendarFeedFetcher($client, new FakeResolver(['93.184.216.34']), 7, 100);

        self::assertSame('BEGIN:VCALENDAR', $fetcher->fetch('https://calendar.example/feed.ics'));
        self::assertSame(['https://calendar.example/feed.ics', '93.184.216.34', 7, 100], $client->request);
    }

    /** @dataProvider unsafeUrls */
    public function testRejectsUnsafeUrlsBeforeRequest(string $url, array $addresses): void
    {
        $client = new FakeFeedClient(new CalendarFeedResponse(200, 'unused'));
        $this->expectException(CalendarFeedFetchException::class);
        try {
            (new SecureCalendarFeedFetcher($client, new FakeResolver($addresses)))->fetch($url);
        } finally {
            self::assertNull($client->request);
        }
    }

    public static function unsafeUrls(): iterable
    {
        yield 'plain HTTP' => ['http://calendar.example/feed.ics', ['93.184.216.34']];
        yield 'credentials' => ['https://user:secret@calendar.example/feed.ics', ['93.184.216.34']];
        yield 'localhost' => ['https://localhost/feed.ics', ['127.0.0.1']];
        yield 'private IPv4 resolution' => ['https://calendar.example/feed.ics', ['10.0.0.3']];
        yield 'loopback IPv6 resolution' => ['https://calendar.example/feed.ics', ['::1']];
        yield 'mixed public/private resolution' => ['https://calendar.example/feed.ics', ['93.184.216.34', '192.168.1.2']];
        yield 'unresolvable' => ['https://calendar.example/feed.ics', []];
    }

    /** @dataProvider badResponses */
    public function testRejectsBadHttpResponses(CalendarFeedResponse $response): void
    {
        $this->expectException(CalendarFeedFetchException::class);
        (new SecureCalendarFeedFetcher(
            new FakeFeedClient($response), new FakeResolver(['93.184.216.34']), 10, 10
        ))->fetch('https://calendar.example/feed.ics');
    }

    public static function badResponses(): iterable
    {
        yield 'redirect (never followed)' => [new CalendarFeedResponse(302, '')];
        yield 'server error' => [new CalendarFeedResponse(503, '')];
        yield 'oversized fake response' => [new CalendarFeedResponse(200, str_repeat('x', 11))];
    }
}

final class FakeResolver implements CalendarHostResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses) {}
    public function resolve(string $host): array { return $this->addresses; }
}

final class FakeFeedClient implements CalendarFeedHttpClient
{
    /** @var array{string,string,int,int}|null */
    public ?array $request = null;
    public function __construct(private CalendarFeedResponse $response) {}
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse
    {
        $this->request = [$url, $resolvedIp, $timeoutSeconds, $maxBytes];
        return $this->response;
    }
}
