<?php
declare(strict_types=1);
namespace Tests\Unit\Calendar;

use App\Application\Calendar\{CalendarFeedHttpClient, CalendarFeedResponse, CalendarHostResolver, CalendarImportService, CalendarSourceRepository, CalendarSyncLogRepository, ExternalCalendarEventRepository, CalendarSyncClock, CalendarSyncLock, CalendarRetryPolicy, CalendarSleeper, CalendarSyncWorker, IcsParser, SecureCalendarFeedFetcher, ImportedEventPersistenceResult};
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CalendarAutomationTest extends TestCase
{
    private const FEED = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:test\r\nDTSTART:20271001\r\nDTEND:20271002\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function testTransientHttpRetriesWithBoundedBackoffAndMetrics(): void
    {
        $client = new SequenceHttp([503, 429, 200]);
        $sleeper = new RecordingSleeper();
        $logs = new RecordingLogs();
        $events = new RecordingEvents();
        $result = $this->service($client, $logs, $events, $sleeper)->import(1);
        self::assertSame('success', $result->status);
        self::assertSame([1, 2], $sleeper->delays);
        self::assertSame(2, $result->retries);
        self::assertSame(2, $logs->metrics['retry_count']);
        self::assertSame(1, $events->reconciliations);
    }

    public function testRetryExhaustionProducesFailedLogAndDoesNotReconcile(): void
    {
        $client = new SequenceHttp([503, 503, 503]);
        $logs = new RecordingLogs();
        $events = new RecordingEvents();
        $result = $this->service($client, $logs, $events)->import(1);
        self::assertSame('failed', $result->status);
        self::assertSame('failed', $logs->statuses[0]);
        self::assertSame(3, $client->calls);
        self::assertSame(0, $events->reconciliations);
    }

    public function testParseFailureAndPermanentHttpFailuresAreNotRetried(): void
    {
        foreach ([200, 400, 401, 403, 404, 408] as $status) {
            $client = new SequenceHttp([$status], 'private invalid https://canary.invalid/secret');
            $logs = new RecordingLogs();
            $events = new RecordingEvents();
            $result = $this->service($client, $logs, $events)->import(1);
            self::assertSame('failed', $result->status);
            self::assertSame(1, $client->calls);
            self::assertSame(0, $events->reconciliations);
            self::assertStringNotContainsString('canary', json_encode($result));
        }
    }

    public function testPartialPersistenceFailureDoesNotReconcileAndReleasesLock(): void
    {
        $events = new RecordingEvents();
        $events->fail = true;
        $lock = new RecordingLock();
        $result = $this->service(new SequenceHttp([200]), new RecordingLogs(), $events, lock: $lock)->import(1);
        self::assertSame('failed', $result->status);
        self::assertSame(0, $events->reconciliations);
        self::assertSame([1], $lock->released);
        self::assertStringNotContainsString('private', json_encode($result));
    }

    public function testBusyLockDoesNotFetchOrCreateFalseSuccessAndWorkerExitsTwo(): void
    {
        $lock = new RecordingLock();
        $lock->busy = true;
        $client = new SequenceHttp([200]);
        $logs = new RecordingLogs();
        $service = $this->service($client, $logs, new RecordingEvents(), lock: $lock);
        $records = [];
        $code = (new CalendarSyncWorker(new AutomationSources(), $service))->run(static function (array $record) use (&$records): void { $records[] = $record; });
        self::assertSame(2, $code);
        self::assertSame('locked', $records[0]['status']);
        self::assertSame(0, $client->calls);
        self::assertSame([], $logs->statuses);
        self::assertSame([], $lock->released);
    }

    public function testWorkerFiltersSourcesContinuesAfterFailureAndNeverLogsPrivateSourceData(): void
    {
        $sources = new AutomationSources([
            ['id'=>1,'enabled'=>true,'direction'=>'import'],
            ['id'=>2,'enabled'=>false,'direction'=>'import'],
            ['id'=>3,'enabled'=>true,'direction'=>'export'],
            ['id'=>4,'enabled'=>true,'direction'=>'bidirectional'],
        ]);
        $logs = new RecordingLogs();
        $client = new SequenceHttp([401, 200]);
        $service = $this->service($client, $logs, new RecordingEvents(), sources: $sources);
        $records = [];
        $code = (new CalendarSyncWorker($sources, $service))->run(static function (array $record) use (&$records): void { $records[] = $record; });
        self::assertSame(1, $code);
        self::assertSame([1, 4], $logs->sources);
        self::assertSame(['failed', 'success'], $logs->statuses);
        self::assertSame(1, $records[2]['successful']);
        self::assertSame(1, $records[2]['failed']);
        self::assertStringNotContainsString('secret', json_encode($records));
        self::assertStringNotContainsString('https:', json_encode($records));
    }

    public function testWorkerSuccessAndNoEnabledSourcesExitZero(): void
    {
        foreach ([new AutomationSources(), new AutomationSources([])] as $sources) {
            $service = $this->service(new SequenceHttp([200]), new RecordingLogs(), new RecordingEvents(), sources: $sources);
            self::assertSame(0, (new CalendarSyncWorker($sources, $service))->run(static function (array $record): void {}));
        }
    }

    public function testBackoffAndGraceConfigurationHaveHardBounds(): void
    {
        self::assertSame(60, (new CalendarRetryPolicy(5, 30))->delay(5));
        $this->expectException(\InvalidArgumentException::class);
        new CalendarRetryPolicy(6);
    }

    private function service(SequenceHttp $client, RecordingLogs $logs, RecordingEvents $events, ?RecordingSleeper $sleeper = null, ?RecordingLock $lock = null, ?AutomationSources $sources = null): CalendarImportService
    {
        $resolver = new class implements CalendarHostResolver { public function resolve(string $host): array { return ['93.184.216.34']; } };
        $clock = new class implements CalendarSyncClock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2027-01-01 12:00:00', new \DateTimeZone('Europe/Budapest')); } };
        return new CalendarImportService($sources ?? new AutomationSources(), $logs, $events, new SecureCalendarFeedFetcher($client, $resolver), new IcsParser(), $clock, $lock ?? new RecordingLock(), new CalendarRetryPolicy(), $sleeper ?? new RecordingSleeper());
    }

    public static function feed(): string { return self::FEED; }
}

final class SequenceHttp implements CalendarFeedHttpClient
{
    public int $calls = 0;
    public function __construct(private array $statuses, private ?string $body = null) {}
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse { ++$this->calls; return new CalendarFeedResponse(array_shift($this->statuses), $this->body ?? CalendarAutomationTest::feed()); }
}
final class RecordingSleeper implements CalendarSleeper { public array $delays = []; public function sleep(int $seconds): void { $this->delays[] = $seconds; } }
final class RecordingLock implements CalendarSyncLock { public bool $busy = false; public array $released = []; public function acquire(int $sourceId): bool { return !$this->busy; } public function release(int $sourceId): void { $this->released[] = $sourceId; } }
final class RecordingLogs implements CalendarSyncLogRepository
{
    public array $sources = []; public array $statuses = []; public array $metrics = [];
    public function recoverInterrupted(int $sourceId, DateTimeImmutable $at): int { return 0; }
    public function start(int $sourceId, DateTimeImmutable $startedAt): int { $this->sources[] = $sourceId; return count($this->sources); }
    public function finish(int $id,string $status,DateTimeImmutable $finishedAt,int $imported,int $exported,array $warnings,array $errors): void { $this->statuses[] = $status; }
    public function metrics(int $id,array $metrics): void { $this->metrics = $metrics; }
    public function recent(?int $sourceId=null,int $limit=100): array { return []; }
}
final class RecordingEvents implements ExternalCalendarEventRepository
{
    public int $reconciliations = 0; public bool $fail = false;
    public function reconcile(int $sourceId,array $seenUids,DateTimeImmutable $now,int $graceSeconds): int { ++$this->reconciliations; return 0; }
    public function findBySourceAndUid(int $sourceId,string $externalUid): ?array { return null; }
    public function upsert(int $sourceId,string $externalUid,?string $summary,?string $description,DateTimeImmutable $startDate,DateTimeImmutable $endDate,string $payloadHash,string $status,DateTimeImmutable $seenAt,?int $blockedPeriodId=null): int { return 1; }
    public function linkBlockedPeriod(int $eventId,int $blockedPeriodId): void {}
    public function importEvent(int $sourceId,string $externalUid,?string $summary,?string $description,DateTimeImmutable $startDate,DateTimeImmutable $endDate,string $payloadHash,DateTimeImmutable $seenAt,bool $cancelled=false): ImportedEventPersistenceResult { if ($this->fail) { throw new \RuntimeException('private URL https://canary.invalid/secret'); } return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::BLOCKED,1,1); }
}
final class AutomationSources implements CalendarSourceRepository
{
    public function __construct(private array $rows = [['id'=>1,'enabled'=>true,'direction'=>'import']]) {}
    public function all(): array { return array_map(static fn (array $row): array => $row + ['provider'=>'szallas_hu','url'=>'https://example.invalid/secret','name'=>'private name'], $this->rows); }
    public function find(int $id): ?array { foreach ($this->all() as $row) { if ($row['id'] === $id) { return $row; } } return null; }
    public function create(string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null): int { return 1; }
    public function update(int $id,string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null): void {}
    public function delete(int $id): void {}
    public function markSuccess(int $id,DateTimeImmutable $at): void {}
    public function markError(int $id,DateTimeImmutable $at): void {}
}
