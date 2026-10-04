<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use App\Application\Availability\GetAvailabilityHandler;
use App\Application\Calendar\CalendarFeedHttpClient;
use App\Application\Calendar\CalendarFeedResponse;
use App\Application\Calendar\CalendarHostResolver;
use App\Application\Calendar\CalendarImportService;
use App\Application\Calendar\CalendarSyncClock;
use App\Application\Calendar\IcsParser;
use App\Application\Calendar\SecureCalendarFeedFetcher;
use App\Application\Calendar\ImportedEventPersistenceResult;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoBlockedPeriodReadRepository;
use App\Infrastructure\Persistence\PdoBookingReadRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportFeedRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportTokenRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarSourceRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarSyncLogRepository;
use App\Infrastructure\Persistence\Calendar\PdoExternalCalendarEventRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CalendarPersistenceTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $sourceIds = [];
    /** @var list<int> */
    private array $blockedPeriodIds = [];
    /** @var list<string> */
    private array $bookingReferences = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->bookingReferences as $reference) {
            $this->pdo->prepare('DELETE FROM bookings WHERE reference = :reference')->execute(['reference' => $reference]);
        }
        foreach ($this->sourceIds as $id) {
            $this->pdo->prepare('DELETE FROM calendar_sources WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->blockedPeriodIds as $id) {
            $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->exec('DELETE FROM calendar_export_tokens WHERE id = 1');
    }

    public function testSourcePersistsTokenOnlyAsHashAndDoesNotExposeIt(): void
    {
        $repository = new PdoCalendarSourceRepository($this->pdo);
        $id = $repository->create('Google import', 'google_calendar', 'https://calendar.example.invalid/private.ics', 'import', true, 'secret-sync-token');
        $this->sourceIds[] = $id;

        $stored = $this->pdo->query("SELECT sync_token_hash FROM calendar_sources WHERE id = {$id}")->fetchColumn();
        self::assertSame(hash('sha256', 'secret-sync-token'), $stored);
        self::assertArrayNotHasKey('sync_token_hash', $repository->find($id) ?? []);
        self::assertStringNotContainsString('secret-sync-token', json_encode($repository->all(), JSON_THROW_ON_ERROR));
    }

    public function testChangedImportUpdatesExistingBlockedPeriodWithoutCreatingAnother(): void
    {
        $sourceId = $this->source();
        $repository = new PdoExternalCalendarEventRepository($this->pdo);
        $first = $repository->importEvent($sourceId, 'same-event', 'Occupied', null, $this->date('2027-08-10'), $this->date('2027-08-13'), hash('sha256', 'first'), $this->now());
        $this->blockedPeriodIds[] = (int) $first->blockedPeriodId;
        $second = $repository->importEvent($sourceId, 'same-event', 'Changed', null, $this->date('2027-08-11'), $this->date('2027-08-14'), hash('sha256', 'changed'), $this->now());

        self::assertSame(ImportedEventPersistenceResult::BLOCKED, $first->outcome);
        self::assertSame(ImportedEventPersistenceResult::BLOCKED, $second->outcome);
        self::assertSame($first->blockedPeriodId, $second->blockedPeriodId);
        $block = $this->pdo->query('SELECT start_date, end_date FROM blocked_periods WHERE id = ' . (int) $first->blockedPeriodId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['start_date' => '2027-08-11', 'end_date' => '2027-08-14'], $block);
    }

    public function testUnchangedImportIsDuplicateAndCancelledEventDeactivatesLinkedBlock(): void
    {
        $sourceId = $this->source();
        $repository = new PdoExternalCalendarEventRepository($this->pdo);
        $hash = hash('sha256', 'same');
        $first = $repository->importEvent($sourceId, 'cancel-me', null, null, $this->date('2027-08-10'), $this->date('2027-08-13'), $hash, $this->now());
        $this->blockedPeriodIds[] = (int) $first->blockedPeriodId;
        $duplicate = $repository->importEvent($sourceId, 'cancel-me', null, null, $this->date('2027-08-10'), $this->date('2027-08-13'), $hash, $this->now());
        self::assertSame(ImportedEventPersistenceResult::DUPLICATE, $duplicate->outcome);
        $removed = $repository->importEvent($sourceId, 'cancel-me', null, null, $this->date('2027-08-10'), $this->date('2027-08-13'), hash('sha256', 'cancelled'), $this->now(), true);
        self::assertSame(ImportedEventPersistenceResult::REMOVED, $removed->outcome);
        self::assertSame(0, (int) $this->pdo->query('SELECT is_active FROM blocked_periods WHERE id = ' . (int) $first->blockedPeriodId)->fetchColumn());
        self::assertSame('removed', $repository->findBySourceAndUid($sourceId, 'cancel-me')['status']);
    }

    public function testNewCancelledEventNeverCreatesBlockedPeriod(): void
    {
        $sourceId = $this->source();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn();
        $result = (new PdoExternalCalendarEventRepository($this->pdo))->importEvent(
            $sourceId, 'already-cancelled', null, null, $this->date('2027-08-10'), $this->date('2027-08-13'), hash('sha256', 'cancelled'), $this->now(), true
        );
        self::assertSame(ImportedEventPersistenceResult::REMOVED, $result->outcome);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn());
    }

    public function testDeletingSourceDeactivatesItsLinkedBlockedPeriods(): void
    {
        $sourceId = $this->source();
        $repository = new PdoExternalCalendarEventRepository($this->pdo);
        $imported = $repository->importEvent($sourceId, 'orphan-guard', null, null, $this->date('2027-08-10'), $this->date('2027-08-13'), hash('sha256', 'one'), $this->now());
        $this->blockedPeriodIds[] = (int) $imported->blockedPeriodId;
        (new PdoCalendarSourceRepository($this->pdo))->delete($sourceId);
        self::assertSame(0, (int) $this->pdo->query('SELECT is_active FROM blocked_periods WHERE id = ' . (int) $imported->blockedPeriodId)->fetchColumn());
        $this->sourceIds = array_values(array_filter($this->sourceIds, static fn (int $id): bool => $id !== $sourceId));
    }

    public function testConfirmedBookingProducesConflictWithoutChangingBookingOrCreatingBlock(): void
    {
        $sourceId = $this->source();
        $reference = 'ICAL-' . bin2hex(random_bytes(4));
        $this->bookingReferences[] = $reference;
        $insert = $this->pdo->prepare(
            "INSERT INTO bookings (reference, status, arrival_date, departure_date, guest_name, guest_email, adults)
             VALUES (:reference, 'confirmed', '2027-09-10', '2027-09-13', 'Guest', 'guest@example.invalid', 1)"
        );
        $insert->execute(['reference' => $reference]);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn();

        $result = (new PdoExternalCalendarEventRepository($this->pdo))->importEvent(
            $sourceId, 'conflicting-event', null, null, $this->date('2027-09-11'), $this->date('2027-09-12'), hash('sha256', 'conflict'), $this->now()
        );

        self::assertSame(ImportedEventPersistenceResult::CONFLICT, $result->outcome);
        self::assertNull($result->blockedPeriodId);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn());
        $status = $this->pdo->prepare('SELECT status FROM bookings WHERE reference = :reference');
        $status->execute(['reference' => $reference]);
        self::assertSame('confirmed', $status->fetchColumn());
    }

    public function testSyncLogStoresCountsButRejectsUrls(): void
    {
        $sourceId = $this->source();
        $repository = new PdoCalendarSyncLogRepository($this->pdo);
        $id = $repository->start($sourceId, $this->now());
        $repository->finish($id, 'warning', $this->now(), 2, 1, ['Confirmed booking conflict.'], []);
        $row = $repository->recent($sourceId, 1)[0];
        self::assertSame(2, (int) $row['imported_count']);
        self::assertSame(1, (int) $row['exported_count']);

        $id = $repository->start($sourceId, $this->now());
        $this->expectException(\InvalidArgumentException::class);
        $repository->finish($id, 'failed', $this->now(), 0, 0, [], ['https://secret.invalid/feed.ics']);
    }

    public function testSzallasHuBareDateFixtureCreatesThreeIdempotentNonExportedAvailabilityBlocks(): void
    {
        $sourceId = $this->source();
        $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Calendar/szallas-hu-all-day.ics');
        self::assertIsString($contents);
        $sources = new PdoCalendarSourceRepository($this->pdo);
        $logs = new PdoCalendarSyncLogRepository($this->pdo);
        $events = new PdoExternalCalendarEventRepository($this->pdo);
        $service = new CalendarImportService(
            $sources,
            $logs,
            $events,
            new SecureCalendarFeedFetcher(new FixtureCalendarHttpClient($contents), new PublicFixtureHostResolver()),
            new IcsParser(),
            new FixedCalendarSyncClock(),
        );

        $first = $service->import($sourceId);
        self::assertSame('success', $first->status);
        self::assertSame(2, $first->imported);
        self::assertSame(0, $first->duplicates);

        $statement = $this->pdo->prepare(
            'SELECT e.external_uid, e.blocked_period_id, bp.start_date, bp.end_date, bp.is_active
             FROM external_calendar_events e
             INNER JOIN blocked_periods bp ON bp.id = e.blocked_period_id
             WHERE e.calendar_source_id = :source_id ORDER BY bp.start_date'
        );
        $statement->execute(['source_id' => $sourceId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame([
            ['2026-08-07', '2026-08-09'],
            ['2026-08-24', '2026-08-26'],
        ], array_map(static fn (array $row): array => [$row['start_date'], $row['end_date']], $rows));
        foreach ($rows as $row) {
            self::assertStringContainsString('@szallas.example', $row['external_uid']);
            self::assertSame(1, (int) $row['is_active']);
            $this->blockedPeriodIds[] = (int) $row['blocked_period_id'];
        }
        $placeholder = $this->pdo->prepare(
            'SELECT status, blocked_period_id FROM external_calendar_events
             WHERE calendar_source_id = :source_id AND external_uid = :uid'
        );
        $placeholder->execute(['source_id' => $sourceId, 'uid' => 'generic-unavailable-3@szallas.example']);
        self::assertSame(['status' => 'removed', 'blocked_period_id' => null], $placeholder->fetch(PDO::FETCH_ASSOC));

        $second = $service->import($sourceId);
        self::assertSame('success', $second->status);
        self::assertSame(0, $second->imported);
        self::assertSame(2, $second->duplicates);
        $statement->execute(['source_id' => $sourceId]);
        self::assertCount(2, $statement->fetchAll());

        $latestLogs = $logs->recent($sourceId, 2);
        self::assertSame('success', $latestLogs[1]['status']);
        self::assertSame(2, (int) $latestLogs[1]['imported_count']);
        self::assertSame([], json_decode($latestLogs[1]['warnings_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([], json_decode($latestLogs[1]['errors_json'], true, flags: JSON_THROW_ON_ERROR));

        $availability = (new GetAvailabilityHandler(
            new PdoBookingReadRepository($this->pdo, ['confirmed']),
            new PdoBlockedPeriodReadRepository($this->pdo),
            today: $this->date('2026-07-18'),
        ))->handle('2026-08-07', '2026-08-10');
        $statuses = array_column($availability['days'], 'status', 'date');
        self::assertSame('blocked', $statuses['2026-08-07']);
        self::assertSame('blocked', $statuses['2026-08-08']);
        self::assertSame('available', $statuses['2026-08-09']);

        $exportedStarts = array_map(
            static fn ($event): string => $event->startDate->format('Y-m-d'),
            (new PdoCalendarExportFeedRepository($this->pdo))->exportableEvents(),
        );
        self::assertNotContains('2026-08-07', $exportedStarts);
        self::assertNotContains('2026-08-24', $exportedStarts);
        self::assertNotContains('2027-01-01', $exportedStarts);

        $failed = (new CalendarImportService(
            $sources,
            $logs,
            $events,
            new SecureCalendarFeedFetcher(new FixtureCalendarHttpClient('invalid feed'), new PublicFixtureHostResolver()),
            new IcsParser(),
            new FixedCalendarSyncClock(),
        ))->import($sourceId);
        self::assertSame('failed', $failed->status);
        self::assertSame('failed', $logs->recent($sourceId, 1)[0]['status']);
    }

    public function testExportTokenVerificationUsesHashAndRotationInvalidatesOldToken(): void
    {
        $repository = new PdoCalendarExportTokenRepository($this->pdo);
        $old = str_repeat('a', 32);
        $new = str_repeat('b', 32);
        $repository->rotate($old, $this->now());
        self::assertTrue($repository->verify($old));
        self::assertFalse($repository->verify('wrong'));
        $repository->rotate($new, $this->now()->modify('+1 minute'));
        self::assertFalse($repository->verify($old));
        self::assertTrue($repository->verify($new));
        self::assertSame(hash('sha256', $new), $this->pdo->query('SELECT token_hash FROM calendar_export_tokens WHERE id = 1')->fetchColumn());
    }

    private function source(): int
    {
        $id = (new PdoCalendarSourceRepository($this->pdo))->create(
            'Source ' . bin2hex(random_bytes(3)), 'szallas_hu', 'https://example.invalid/feed.ics', 'import', true
        );
        $this->sourceIds[] = $id;
        return $id;
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('Europe/Budapest'));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-01-01 12:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

final readonly class FixtureCalendarHttpClient implements CalendarFeedHttpClient
{
    public function __construct(private string $contents) {}
    public function get(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): CalendarFeedResponse
    {
        return new CalendarFeedResponse(200, $this->contents);
    }
}

final class PublicFixtureHostResolver implements CalendarHostResolver
{
    public function resolve(string $host): array { return ['93.184.216.34']; }
}

final class FixedCalendarSyncClock implements CalendarSyncClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-18 16:30:00', new DateTimeZone('Europe/Budapest'));
    }
}
