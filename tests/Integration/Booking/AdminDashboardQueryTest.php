<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminDashboardRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminDashboardQueryTest extends TestCase
{
    private PDO $pdo;
    private PdoAdminDashboardRepository $repository;
    /** @var list<int> */
    private array $bookingIds = [];
    private string $prefix;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->repository = new PdoAdminDashboardRepository($this->pdo);
        $this->prefix = 'DASH-' . strtoupper(bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach ($this->bookingIds as $id) {
            $statement = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
    }

    public function testOperationalProjectionHonorsBudapestBoundariesStatusesAndUpcomingLimit(): void
    {
        $clockInstant = new DateTimeImmutable('2094-06-09 22:30:00', new DateTimeZone('UTC'));
        $baseline = $this->repository->fetch($clockInstant);

        $pendingToday = $this->insert('PENDING', 'pending', '2094-06-10', '2094-06-12');
        $arrivalToday = $this->insert('ARRIVAL', 'confirmed', '2094-06-10', '2094-06-12');
        $departureToday = $this->insert('DEPARTURE', 'confirmed', '2094-06-08', '2094-06-10');
        $activeStay = $this->insert('ACTIVE', 'confirmed', '2094-06-09', '2094-06-13');
        $upcoming = [
            $this->insert('UP-1', 'confirmed', '2094-06-11', '2094-06-12'),
            $this->insert('UP-2', 'confirmed', '2094-06-12', '2094-06-14'),
            $this->insert('UP-3', 'confirmed', '2094-06-13', '2094-06-16'),
            $this->insert('UP-4', 'confirmed', '2094-06-14', '2094-06-15'),
            $this->insert('UP-7-A', 'confirmed', '2094-06-17', '2094-06-19'),
            $this->insert('UP-7-B', 'confirmed', '2094-06-17', '2094-06-18'),
        ];
        $plusEight = $this->insert('UP-8', 'confirmed', '2094-06-18', '2094-06-19');
        $this->insert('COMPLETED', 'completed', '2094-06-01', '2094-06-03');
        $cancelled = $this->insert('CANCELLED', 'cancelled', '2094-06-10', '2094-06-12');
        $rejected = $this->insert('REJECTED', 'rejected', '2094-06-08', '2094-06-10');
        $invalidated = $this->insert('INVALIDATED', 'invalidated', '2094-06-11', '2094-06-12');

        $overview = $this->repository->fetch($clockInstant);
        $arrivals = $this->ours($overview->arrivalsToday);
        $departures = $this->ours($overview->departuresToday);
        $visibleUpcoming = $this->ours($overview->upcomingArrivals);

        self::assertSame(PdoAdminDashboardRepository::QUERY_COUNT, 4);
        self::assertSame($baseline->pendingCount + 1, $overview->pendingCount);
        self::assertSame($baseline->arrivalsTodayCount + 1, $overview->arrivalsTodayCount);
        self::assertSame($baseline->departuresTodayCount + 1, $overview->departuresTodayCount);
        self::assertSame($baseline->currentGuestsCount + 2, $overview->currentGuestsCount);
        self::assertSame([$arrivalToday], array_column($arrivals, 'reference'));
        self::assertSame([$departureToday], array_column($departures, 'reference'));
        self::assertNotContains($pendingToday, array_column($arrivals, 'reference'));
        self::assertNotContains($cancelled, array_column($arrivals, 'reference'));
        self::assertNotContains($rejected, array_column($departures, 'reference'));
        self::assertCount(5, $visibleUpcoming);
        self::assertSame(array_slice($upcoming, 0, 5), array_column($visibleUpcoming, 'reference'));
        self::assertSame('2094-06-17', $visibleUpcoming[4]['arrival_date']);
        self::assertNotContains($arrivalToday, array_column($visibleUpcoming, 'reference'));
        self::assertNotContains($plusEight, array_column($visibleUpcoming, 'reference'));
        self::assertNotContains($invalidated, array_column($visibleUpcoming, 'reference'));
        self::assertTrue($overview->hasMoreUpcoming);
        self::assertSame(2, $arrivals[0]['adults']);
        self::assertSame(1, $arrivals[0]['children']);
        self::assertSame(3, $arrivals[0]['party_size']);
        self::assertSame(2, $arrivals[0]['nights']);
        self::assertArrayNotHasKey('guest_email', $arrivals[0]);
        self::assertArrayNotHasKey('guest_phone', $arrivals[0]);
        self::assertNotContains($activeStay, array_column($arrivals, 'reference'));
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function ours(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => str_starts_with((string) $row['reference'], $this->prefix),
        ));
    }

    private function insert(string $suffix, string $status, string $arrival, string $departure): string
    {
        $reference = $this->prefix . '-' . $suffix;
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email, guest_phone, adults, children, total_amount, currency)
             VALUES
                (:reference, :status, :arrival, :departure, :name, :email, :phone, 2, 1, 30000, \'HUF\')'
        );
        $statement->execute([
            'reference' => $reference,
            'status' => $status,
            'arrival' => $arrival,
            'departure' => $departure,
            'name' => 'Dashboard Guest ' . $suffix,
            'email' => strtolower($reference) . '@example.invalid',
            'phone' => '+3612345678',
        ]);
        $this->bookingIds[] = (int) $this->pdo->lastInsertId();

        return $reference;
    }
}
