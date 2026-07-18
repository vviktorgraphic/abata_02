<?php

declare(strict_types=1);

namespace Tests\Integration\Calendar;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportFeedRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoCalendarExportFeedRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFeedIncludesOnlyConfirmedAndLocallyManagedActivePeriods(): void
    {
        $booking = $this->pdo->prepare(
            'INSERT INTO bookings
             (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children, total_amount, currency)
             VALUES (:reference, :status, :arrival, :departure, :name, :email, 1, 0, 1000, \'HUF\')'
        );
        foreach (['confirmed', 'pending', 'rejected', 'cancelled', 'invalidated'] as $index => $status) {
            $booking->execute([
                'reference' => 'ICAL-' . $status . '-' . bin2hex(random_bytes(4)),
                'status' => $status,
                'arrival' => '2027-05-' . str_pad((string) (1 + $index * 3), 2, '0', STR_PAD_LEFT),
                'departure' => '2027-05-' . str_pad((string) (3 + $index * 3), 2, '0', STR_PAD_LEFT),
                'name' => 'Private Guest',
                'email' => 'private@example.invalid',
            ]);
        }

        $period = $this->pdo->prepare('INSERT INTO blocked_periods (start_date, end_date, reason, is_active) VALUES (:start, :end, :reason, :active)');
        $period->execute(['start' => '2027-06-01', 'end' => '2027-06-03', 'reason' => 'Local', 'active' => 1]);
        $period->execute(['start' => '2027-06-04', 'end' => '2027-06-06', 'reason' => 'Removed', 'active' => 0]);
        $period->execute(['start' => '2027-06-07', 'end' => '2027-06-09', 'reason' => 'Imported private title', 'active' => 1]);
        $importedPeriodId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO calendar_sources (name, provider, url, direction, enabled) VALUES ('Test', 'google_calendar', 'https://example.invalid/feed.ics', 'import', 1)");
        $sourceId = (int) $this->pdo->lastInsertId();
        $external = $this->pdo->prepare(
            "INSERT INTO external_calendar_events
             (calendar_source_id, external_uid, start_date, end_date, payload_hash, blocked_period_id, status, last_seen_at)
             VALUES (:source, 'external-uid', '2027-06-07', '2027-06-09', :hash, :blocked, 'blocked', NOW())"
        );
        $external->execute(['source' => $sourceId, 'hash' => hash('sha256', 'event'), 'blocked' => $importedPeriodId]);

        $events = (new PdoCalendarExportFeedRepository($this->pdo))->exportableEvents();

        $ours = array_values(array_filter($events, static fn ($event): bool => in_array(
            $event->startDate->format('Y-m-d'),
            ['2027-05-01', '2027-05-04', '2027-05-07', '2027-05-10', '2027-05-13', '2027-06-01', '2027-06-04', '2027-06-07'],
            true,
        )));
        self::assertCount(2, $ours);
        self::assertSame(['2027-05-01', '2027-06-01'], array_map(static fn ($event) => $event->startDate->format('Y-m-d'), $ours));
        self::assertSame(['2027-05-03', '2027-06-03'], array_map(static fn ($event) => $event->endDate->format('Y-m-d'), $ours));
        self::assertSame('Europe/Budapest', $ours[0]->startDate->getTimezone()->getName());
        self::assertStringNotContainsString('Private Guest', implode(' ', array_map(static fn ($event) => $event->uid, $events)));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}@calendar\.local$/', $ours[1]->uid);
    }
}
