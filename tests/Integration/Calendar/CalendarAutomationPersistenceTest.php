<?php
declare(strict_types=1);
namespace Tests\Integration\Calendar;

use App\Application\Calendar\{CalendarFeedHttpClient, CalendarFeedResponse, CalendarHostResolver, CalendarImportService, CalendarRetryPolicy, CalendarSyncClock, IcsParser, SecureCalendarFeedFetcher};
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Calendar\{PdoCalendarSourceRepository, PdoCalendarSyncLogRepository, PdoCalendarSyncLock, PdoExternalCalendarEventRepository};
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CalendarAutomationPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $sourceId;
    private AutomationFixtureClient $http;
    private AutomationFixtureClock $clock;
    private CalendarImportService $service;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) { self::markTestSkipped('DB not configured.'); }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $sources = new PdoCalendarSourceRepository($this->pdo);
        $this->sourceId = $sources->create('Automation test', 'szallas_hu', 'https://example.invalid/fixture.ics', 'import', true);
        $this->clock = new AutomationFixtureClock();
        $this->http = new AutomationFixtureClient();
        $resolver = new class implements CalendarHostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        };
        $this->service = new CalendarImportService($sources, new PdoCalendarSyncLogRepository($this->pdo),
            new PdoExternalCalendarEventRepository($this->pdo), new SecureCalendarFeedFetcher($this->http, $resolver),
            new IcsParser(), $this->clock, new PdoCalendarSyncLock($this->pdo), new CalendarRetryPolicy(0));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) { return; }
        $select = $this->pdo->prepare('SELECT blocked_period_id FROM external_calendar_events WHERE calendar_source_id = :source');
        $select->execute(['source' => $this->sourceId]);
        $ids = $select->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->prepare('DELETE FROM calendar_sources WHERE id = :id')->execute(['id' => $this->sourceId]);
        foreach ($ids as $id) {
            if ($id !== null) { $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id')->execute(['id' => $id]); }
        }
    }

    public function testDuplicateRefreshesLastSeenAndGraceRemovalAndReappearanceReuseBlock(): void
    {
        self::assertSame(1, $this->service->import($this->sourceId)->imported);
        $first = $this->event();
        $this->clock->at = $this->clock->at->modify('+1 hour');
        self::assertSame(1, $this->service->import($this->sourceId)->duplicates);
        self::assertNotSame($first['last_seen_at'], $this->event()['last_seen_at']);
        $this->http->body = "BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR\n";
        self::assertSame(0, $this->service->import($this->sourceId)->graceInactivated);
        self::assertNotNull($this->event()['missing_since_timestamp']);
        $this->clock->at = $this->clock->at->modify('+23 hours');
        self::assertSame(0, $this->service->import($this->sourceId)->graceInactivated);
        self::assertSame('blocked', $this->event()['status']);
        $this->clock->at = $this->clock->at->modify('+1 hour');
        self::assertSame(1, $this->service->import($this->sourceId)->graceInactivated);
        self::assertSame('removed', $this->event()['status']);
        $this->http->body = AutomationFixtureClient::FEED;
        self::assertSame(1, $this->service->import($this->sourceId)->updated);
        self::assertSame($first['blocked_period_id'], $this->event()['blocked_period_id']);
        self::assertNull($this->event()['missing_since_timestamp']);
        self::assertSame('blocked', $this->event()['status']);
    }

    public function testMalformedNonEventComponentNeverStartsReconciliation(): void
    {
        $this->service->import($this->sourceId);
        $this->http->body = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VTODO\nEND:VCALENDAR\n";
        $this->clock->at = $this->clock->at->modify('+48 hours');
        $result = $this->service->import($this->sourceId);
        self::assertSame('failed', $result->status);
        self::assertNull($this->event()['missing_since_timestamp']);
        self::assertSame('blocked', $this->event()['status']);
        self::assertSame('failed', (new PdoCalendarSyncLogRepository($this->pdo))->recent($this->sourceId, 1)[0]['status']);
    }

    public function testGraceUsesElapsedTimeAcrossRepeatedAutumnHour(): void
    {
        $this->clock->at = new DateTimeImmutable('2026-10-25T02:30:00+01:00');
        $this->service->import($this->sourceId);
        $this->http->body = "BEGIN:VCALENDAR\nEND:VCALENDAR\n";
        $this->service->import($this->sourceId);
        $this->clock->at = new DateTimeImmutable('2026-10-26T01:30:00+01:00');
        self::assertSame(0, $this->service->import($this->sourceId)->graceInactivated);
        $this->clock->at = new DateTimeImmutable('2026-10-26T02:30:00+01:00');
        self::assertSame(1, $this->service->import($this->sourceId)->graceInactivated);
    }

    public function testCancelledMetricsCountOnlyActualActiveBlockTransitions(): void
    {
        $this->service->import($this->sourceId);
        $this->http->body = str_replace('END:VEVENT', "STATUS:CANCELLED\nEND:VEVENT", AutomationFixtureClient::FEED);
        self::assertSame(1, $this->service->import($this->sourceId)->inactivated);
        self::assertSame(0, $this->service->import($this->sourceId)->inactivated);
        $this->http->body = str_replace('UID:automation', 'UID:unknown-cancelled', $this->http->body);
        self::assertSame(0, $this->service->import($this->sourceId)->inactivated);
        self::assertSame(0, (int) (new PdoCalendarSyncLogRepository($this->pdo))->recent($this->sourceId, 1)[0]['inactive_count']);
    }

    public function testCompetingConnectionCannotImportAndDisconnectReleasesStaleLock(): void
    {
        $otherPdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $otherLock = new PdoCalendarSyncLock($otherPdo);
        self::assertTrue($otherLock->acquire($this->sourceId));
        self::assertSame('locked', $this->service->import($this->sourceId)->status);
        self::assertSame(0, $this->http->calls);
        (new PdoCalendarSyncLogRepository($otherPdo))->start($this->sourceId, $this->clock->at);
        unset($otherLock, $otherPdo); // MySQL session ends without RELEASE_LOCK.
        $result = $this->service->import($this->sourceId);
        self::assertSame('success', $result->status);
        self::assertSame(1, $result->recoveredRuns);
        $logs = (new PdoCalendarSyncLogRepository($this->pdo))->recent($this->sourceId, 2);
        self::assertSame('failed', $logs[1]['status']);
        self::assertStringContainsString('interrupted_run_recovered', $logs[1]['errors_json']);
        $probe = new PdoCalendarSyncLock($this->pdo);
        self::assertTrue($probe->acquire($this->sourceId));
        $probe->release($this->sourceId);
    }

    public function testChangedEventConflictingWithConfirmedBookingCountsActualInactivation(): void
    {
        $this->service->import($this->sourceId);
        $insert = $this->pdo->prepare("INSERT INTO bookings (reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children)
            VALUES (:ref,'confirmed','2040-09-01','2040-09-03','Test','test@example.invalid',1,0)");
        $insert->execute(['ref' => 'ICAL-' . bin2hex(random_bytes(6))]);
        $bookingId = (int) $this->pdo->lastInsertId();
        try {
            $this->http->body = str_replace(['20400801', '20400803'], ['20400901', '20400903'], AutomationFixtureClient::FEED);
            $result = $this->service->import($this->sourceId);
            self::assertSame('warning', $result->status);
            self::assertSame(1, $result->inactivated);
            self::assertSame('conflict', $this->event()['status']);
            self::assertSame(1, (int) (new PdoCalendarSyncLogRepository($this->pdo))->recent($this->sourceId, 1)[0]['inactive_count']);
            self::assertSame(0, $this->service->import($this->sourceId)->inactivated);
        } finally {
            $this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id' => $bookingId]);
        }
    }

    private function event(): array
    {
        return (new PdoExternalCalendarEventRepository($this->pdo))->findBySourceAndUid($this->sourceId, 'automation');
    }
}

final class AutomationFixtureClient implements CalendarFeedHttpClient
{
    public const FEED = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:automation\nDTSTART:20400801\nDTEND:20400803\nEND:VEVENT\nEND:VCALENDAR\n";
    public string $body = self::FEED;
    public int $calls = 0;
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse
    {
        ++$this->calls;
        return new CalendarFeedResponse(200, $this->body);
    }
}

final class AutomationFixtureClock implements CalendarSyncClock
{
    public DateTimeImmutable $at;
    public function __construct() { $this->at = new DateTimeImmutable('2040-01-01 12:00:00', new DateTimeZone('Europe/Budapest')); }
    public function now(): DateTimeImmutable { return $this->at; }
}
