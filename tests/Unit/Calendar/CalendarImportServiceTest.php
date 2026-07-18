<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Application\Calendar\BudapestCalendarSyncClock;
use App\Application\Calendar\CalendarFeedHttpClient;
use App\Application\Calendar\CalendarFeedResponse;
use App\Application\Calendar\CalendarHostResolver;
use App\Application\Calendar\CalendarImportService;
use App\Application\Calendar\CalendarSourceRepository;
use App\Application\Calendar\CalendarSyncClock;
use App\Application\Calendar\CalendarSyncLogRepository;
use App\Application\Calendar\ExternalCalendarEventRepository;
use App\Application\Calendar\IcsParser;
use App\Application\Calendar\ImportedEventPersistenceResult;
use App\Application\Calendar\SecureCalendarFeedFetcher;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CalendarImportServiceTest extends TestCase
{
    public function testImportsProviderFeedAsHalfOpenBudapestBlockedPeriodsAndLogsOutcome(): void
    {
        $events = new FakeImportEvents([
            ImportedEventPersistenceResult::BLOCKED,
            ImportedEventPersistenceResult::DUPLICATE,
            ImportedEventPersistenceResult::CONFLICT,
        ]);
        $sources = new FakeImportSources($this->source('google_calendar'));
        $logs = new FakeImportLogs();
        $result = $this->service($sources, $logs, $events, $this->feed([
            $this->event('one', '20271001', '20271003'),
            $this->event('two', '20271003T220000Z', '20271004T013000Z', false),
            $this->event('three', '20271010', '20271011'),
        ]))->import(4);

        self::assertSame('warning', $result->status);
        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->duplicates);
        self::assertCount(1, $result->warnings);
        self::assertSame(['2027-10-01', '2027-10-03'], [$events->calls[0]['start'], $events->calls[0]['end']]);
        // 22:00Z is midnight in Budapest and 01:30Z ends during the next local day.
        self::assertSame(['2027-10-04', '2027-10-05'], [$events->calls[1]['start'], $events->calls[1]['end']]);
        self::assertSame('warning', $logs->finished['status']);
        self::assertSame(1, $logs->finished['imported']);
        self::assertTrue($sources->success);
        self::assertFalse($sources->error);
    }

    public function testSzallasHuUsesSameStandardsCompliantImportPipeline(): void
    {
        $events = new FakeImportEvents([ImportedEventPersistenceResult::BLOCKED]);
        $result = $this->service(new FakeImportSources($this->source('szallas_hu')), new FakeImportLogs(), $events,
            $this->feed([$this->event('szallas-1', '20271101', '20271102')]))->import(4);
        self::assertSame('success', $result->status);
        self::assertCount(1, $events->calls);
    }

    public function testFetchOrParseFailureIsRecordedAndMarksSourceError(): void
    {
        $sources = new FakeImportSources($this->source('google_calendar'));
        $logs = new FakeImportLogs();
        $result = $this->service($sources, $logs, new FakeImportEvents([]), 'invalid')->import(4);
        self::assertSame('failed', $result->status);
        self::assertNotEmpty($result->errors);
        self::assertTrue($sources->error);
        self::assertFalse($sources->success);
        self::assertSame('failed', $logs->finished['status']);
    }

    public function testCancelledEventIsPassedToPersistenceAndUidIsNotLeakedToLogs(): void
    {
        $uid = 'private-reservation-123@example.invalid';
        $events = new FakeImportEvents([ImportedEventPersistenceResult::CONFLICT]);
        $logs = new FakeImportLogs();
        $result = $this->service(new FakeImportSources($this->source('google_calendar')), $logs, $events,
            $this->feed([$this->event($uid, '20271101', '20271102')]))->import(4);
        self::assertFalse($events->calls[0]['cancelled']);
        self::assertStringNotContainsString($uid, implode(' ', $result->warnings));

        $cancelled = new FakeImportEvents([ImportedEventPersistenceResult::REMOVED]);
        $body = $this->feed([str_replace('SUMMARY:Reserved', "STATUS:CANCELLED\r\nSUMMARY:Reserved", $this->event($uid, '20271101', '20271102'))]);
        $result = $this->service(new FakeImportSources($this->source('google_calendar')), new FakeImportLogs(), $cancelled, $body)->import(4);
        self::assertTrue($cancelled->calls[0]['cancelled']);
        self::assertSame('success', $result->status);
    }

    /** @return array<string,mixed> */
    private function source(string $provider): array
    {
        return ['id' => 4, 'provider' => $provider, 'url' => 'https://calendar.example/feed.ics', 'direction' => 'import', 'enabled' => true];
    }

    private function service(FakeImportSources $sources, FakeImportLogs $logs, FakeImportEvents $events, string $body): CalendarImportService
    {
        $client = new class($body) implements CalendarFeedHttpClient {
            public function __construct(private string $body) {}
            public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse { return new CalendarFeedResponse(200, $this->body); }
        };
        $resolver = new class() implements CalendarHostResolver { public function resolve(string $host): array { return ['93.184.216.34']; } };
        $clock = new class() implements CalendarSyncClock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2027-01-02 10:00:00', new DateTimeZone('Europe/Budapest')); }
        };
        return new CalendarImportService($sources, $logs, $events, new SecureCalendarFeedFetcher($client, $resolver), new IcsParser(), $clock);
    }

    /** @param list<string> $events */
    private function feed(array $events): string { return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', $events) . "END:VCALENDAR\r\n"; }
    private function event(string $uid, string $start, string $end, bool $allDay = true): string
    {
        $type = $allDay ? ';VALUE=DATE' : '';
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART{$type}:{$start}\r\nDTEND{$type}:{$end}\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\n";
    }
}

final class FakeImportSources implements CalendarSourceRepository
{
    public bool $success = false; public bool $error = false;
    public function __construct(private array $source) {}
    public function all(): array { return [$this->source]; }
    public function find(int $id): ?array { return $this->source; }
    public function create(string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null): int { return 1; }
    public function update(int $id,string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null): void {}
    public function delete(int $id): void {}
    public function markSuccess(int $id, DateTimeImmutable $at): void { $this->success = true; }
    public function markError(int $id, DateTimeImmutable $at): void { $this->error = true; }
}

final class FakeImportLogs implements CalendarSyncLogRepository
{
    public array $finished = [];
    public function start(int $sourceId, DateTimeImmutable $startedAt): int { return 8; }
    public function finish(int $id,string $status,DateTimeImmutable $finishedAt,int $imported,int $exported,array $warnings,array $errors): void { $this->finished = compact('status','imported','exported','warnings','errors'); }
    public function recent(?int $sourceId=null,int $limit=100): array { return []; }
}

final class FakeImportEvents implements ExternalCalendarEventRepository
{
    public array $calls = [];
    public function __construct(private array $outcomes) {}
    public function findBySourceAndUid(int $sourceId,string $externalUid): ?array { return null; }
    public function upsert(int $sourceId,string $externalUid,?string $summary,?string $description,DateTimeImmutable $startDate,DateTimeImmutable $endDate,string $payloadHash,string $status,DateTimeImmutable $seenAt,?int $blockedPeriodId=null): int { return 1; }
    public function linkBlockedPeriod(int $eventId,int $blockedPeriodId): void {}
    public function importEvent(int $sourceId,string $externalUid,?string $summary,?string $description,DateTimeImmutable $startDate,DateTimeImmutable $endDate,string $payloadHash,DateTimeImmutable $seenAt,bool $cancelled=false): ImportedEventPersistenceResult
    {
        $this->calls[] = ['uid'=>$externalUid,'start'=>$startDate->format('Y-m-d'),'end'=>$endDate->format('Y-m-d'),'cancelled'=>$cancelled];
        $outcome = array_shift($this->outcomes);
        return new ImportedEventPersistenceResult($outcome, count($this->calls), $outcome === ImportedEventPersistenceResult::BLOCKED ? count($this->calls) : null);
    }
}
