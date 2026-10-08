<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminMonthlyOccupancyRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminMonthlyOccupancyRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function test_fetches_only_blocking_bookings_and_active_overlapping_blocks_with_source_metadata(): void
    {
        $legacyBooking = $this->booking('MONTHLY-' . bin2hex(random_bytes(4)), 'pending', '2048-10-03', '2048-10-05');
        $this->booking('MONTHLY-' . bin2hex(random_bytes(4)), 'confirmed', '2048-09-28', '2048-10-01');
        $this->booking('MONTHLY-' . bin2hex(random_bytes(4)), 'cancelled', '2048-10-03', '2048-10-05');
        $this->legacy($legacyBooking);

        $manual = $this->block('2048-10-10', '2048-10-12', true, 'Karbantartás');
        $this->block('2048-10-14', '2048-10-16', false, 'Inaktív');
        $external = $this->block('2048-10-11', '2048-10-13', true, 'Imported');
        $source = $this->calendarSource();
        $statement = $this->pdo->prepare(
            "INSERT INTO external_calendar_events (calendar_source_id, external_uid, summary, start_date, end_date, payload_hash, blocked_period_id, status, last_seen_at)
             VALUES (:source, :uid, '<Partner>', '2048-10-11', '2048-10-13', :hash, :block, 'blocked', '2048-01-01 12:00:00')"
        );
        $statement->execute(['source' => $source, 'uid' => 'monthly-' . bin2hex(random_bytes(8)), 'hash' => hash('sha256', 'monthly'), 'block' => $external]);

        $result = (new PdoAdminMonthlyOccupancyRepository($this->pdo))->fetch(
            new AdminMonthlyOccupancyQuery('2048-10', new DateTimeImmutable('2048-10-08')),
        );

        self::assertCount(31, $result['days']);
        self::assertSame(['free', 'departure'], $this->badgeKeys($this->day($result, '2048-10-01')));
        self::assertSame(['arrival'], $this->badgeKeys($this->day($result, '2048-10-03')));
        self::assertTrue($this->day($result, '2048-10-03')['bookings'][0]['legacy']);
        self::assertSame(['blocked', 'external'], $this->badgeKeys($this->day($result, '2048-10-11')));
        self::assertSame('Szallas.hu', $this->day($result, '2048-10-11')['blocks'][1]['provider_label']);
        self::assertSame('Partner forrás', $this->day($result, '2048-10-11')['blocks'][1]['source_name']);
        self::assertSame($manual, $this->day($result, '2048-10-10')['blocks'][0]['id']);
        self::assertSame(['free'], $this->badgeKeys($this->day($result, '2048-10-14')));
    }

    private function booking(string $reference, string $status, string $arrival, string $departure): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO bookings (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, total_amount, currency)
             VALUES (:reference, :status, :arrival, :departure, 'Havi Vendég', 'monthly@example.invalid', 1, 10000, 'HUF')"
        );
        $statement->execute(compact('reference', 'status', 'arrival', 'departure'));
        return (int) $this->pdo->lastInsertId();
    }

    private function legacy(int $bookingId): void
    {
        $batchId = '11111111-2222-4333-8444-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $this->pdo->prepare(
            "INSERT INTO legacy_booking_import_batches (batch_id, source_system, status, total_rows, imported_rows, started_at, completed_at)
             VALUES (:batch, 'wpbs', 'committed', 1, 1, '2048-01-01 12:00:00', '2048-01-01 12:00:01')"
        )->execute(['batch' => $batchId]);
        $importBatchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO legacy_booking_imports (import_batch_id, source_system, source_booking_id, booking_id, source_status, mapped_status, source_data_hash, imported_at)
             VALUES (:batch, 'wpbs', :source_id, :booking, 'confirmed', 'pending', UNHEX(:hash), '2048-01-01 12:00:00')"
        )->execute(['batch' => $importBatchId, 'source_id' => 'monthly-' . bin2hex(random_bytes(5)), 'booking' => $bookingId, 'hash' => hash('sha256', 'legacy')]);
    }

    private function block(string $start, string $end, bool $active, string $reason): int
    {
        $statement = $this->pdo->prepare('INSERT INTO blocked_periods (start_date, end_date, reason, is_active) VALUES (:start, :end, :reason, :active)');
        $statement->execute(['start' => $start, 'end' => $end, 'reason' => $reason, 'active' => $active ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function calendarSource(): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO calendar_sources (name, provider, url, direction) VALUES ('Partner forrás', 'szallas_hu', :url, 'import')"
        );
        $statement->execute(['url' => 'https://example.invalid/' . bin2hex(random_bytes(4)) . '.ics']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function day(array $result, string $date): array
    {
        foreach ($result['days'] as $day) if ($day['date'] === $date) return $day;
        self::fail('Day not found: ' . $date);
    }

    /** @param array<string, mixed> $day @return list<string> */
    private function badgeKeys(array $day): array { return array_column($day['badges'], 'key'); }
}
