<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\AdminBookingDetailQuery;
use App\Application\Booking\AdminBookingListQuery;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminBookingQueryTest extends TestCase
{
    private PDO $pdo;
    private PdoAdminBookingQueryRepository $repository;
    /** @var list<int> */
    private array $bookingIds = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->repository = new PdoAdminBookingQueryRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->bookingIds as $id) {
            $statement = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
    }

    public function testListSearchFiltersCountAndPaginationUseTheSameCriteria(): void
    {
        $this->insert('ADMIN-Q-A', 'pending', '2045-01-10', 'Anna Search', 'anna@example.invalid', '+361111111');
        $this->insert('ADMIN-Q-B', 'confirmed', '2045-02-10', 'Bela Other', 'bela@example.invalid', '+362222222');
        $this->insert('ADMIN-Q-C', 'pending', '2045-01-20', 'Anna Second', 'second@example.invalid', '+363333333');

        $query = new AdminBookingListQuery([
            'status' => 'pending',
            'arrivalFrom' => new DateTimeImmutable('2045-01-01'),
            'arrivalUntil' => new DateTimeImmutable('2045-01-31'),
            'search' => 'Anna',
            'page' => 2,
            'pageSize' => 1,
        ]);
        $rows = $this->repository->fetchBookingList($query);

        self::assertSame(2, $this->repository->countBookings($query));
        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame(1, $rows[0]['nights']);
        self::assertSame(3, $rows[0]['party_size']);
        self::assertSame('HUF', $rows[0]['currency']);
    }

    /** @dataProvider searchableFields */
    public function testSearchCoversRequiredFields(string $needle, string $reference): void
    {
        $this->insert('ADMIN-Q-SEARCH', 'pending', '2045-03-10', 'Keresett Vendeg', 'unique@example.invalid', '+369999999');
        $rows = $this->repository->fetchBookingList(new AdminBookingListQuery(['search' => $needle]));

        self::assertContains($reference, array_column($rows, 'reference'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function searchableFields(): iterable
    {
        yield 'reference' => ['ADMIN-Q-SEARCH', 'ADMIN-Q-SEARCH'];
        yield 'contact name' => ['Keresett', 'ADMIN-Q-SEARCH'];
        yield 'email' => ['unique@', 'ADMIN-Q-SEARCH'];
        yield 'phone' => ['+369999999', 'ADMIN-Q-SEARCH'];
    }

    public function testDetailReadsChildAgesHistorySnapshotAndEmailStateFromActualSchema(): void
    {
        $id = $this->insert('ADMIN-Q-DETAIL', 'confirmed', '2045-04-10', 'Detail Guest', 'detail@example.invalid', '+364444444');
        $this->pdo->prepare('INSERT INTO booking_child_ages (booking_id, position, age) VALUES (:first_id, 0, 6), (:second_id, 1, 11)')->execute(['first_id' => $id, 'second_id' => $id]);
        $this->pdo->prepare("INSERT INTO booking_status_history (booking_id, old_status, new_status, note) VALUES (:id, 'pending', 'confirmed', 'Approved')")->execute(['id' => $id]);
        $this->pdo->prepare('INSERT INTO booking_pricing_snapshots (booking_id, snapshot) VALUES (:id, :snapshot)')->execute(['id' => $id, 'snapshot' => json_encode(['version' => 1, 'base_unit' => 'person_night'], JSON_THROW_ON_ERROR)]);
        $this->pdo->prepare("INSERT INTO email_outbox (booking_id, message_type, recipient, subject, payload, status, attempts, last_error) VALUES (:id, 'booking_confirmed', 'detail@example.invalid', 'Confirmed', '{}', 'failed', 1, 'SMTP unavailable')")->execute(['id' => $id]);

        $detail = $this->repository->fetchBookingDetail(new AdminBookingDetailQuery('ADMIN-Q-DETAIL'));

        self::assertNotNull($detail);
        self::assertSame([6, 11], $detail['children_ages']);
        self::assertSame('confirmed', $detail['status_history'][0]['status']);
        self::assertSame('person_night', $detail['pricing_snapshot']['base_unit']);
        self::assertSame('failed', $detail['email_outbox'][0]['status']);
        self::assertSame('SMTP unavailable', $detail['email_outbox'][0]['last_error']);
        self::assertNull($detail['privacy_accepted_at']);
    }

    public function testDetailCanUseNumericIdAndUnknownReturnsNull(): void
    {
        $id = $this->insert('ADMIN-Q-ID', 'pending', '2045-05-10', 'Id Guest', 'id@example.invalid', null);

        self::assertSame('ADMIN-Q-ID', $this->repository->fetchBookingDetail(new AdminBookingDetailQuery((string) $id))['reference']);
        self::assertNull($this->repository->fetchBookingDetail(new AdminBookingDetailQuery('ADMIN-Q-UNKNOWN')));
    }

    /** @dataProvider invalidQueries */
    public function testInvalidListQueriesAreRejected(array $filters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AdminBookingListQuery($filters);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'status' => [['status' => 'deleted']];
        yield 'page' => [['page' => 0]];
        yield 'page size low' => [['pageSize' => 0]];
        yield 'page size high' => [['pageSize' => 101]];
        yield 'arrival range' => [['arrivalFrom' => new DateTimeImmutable('2045-02-01'), 'arrivalUntil' => new DateTimeImmutable('2045-01-01')]];
        yield 'created range' => [['createdFrom' => new DateTimeImmutable('2045-02-01'), 'createdUntil' => new DateTimeImmutable('2045-01-01')]];
    }

    private function insert(string $reference, string $status, string $arrival, string $name, string $email, ?string $phone): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings (reference, status, arrival_date, departure_date, guest_name, guest_email, guest_phone, adults, children, total_amount, currency)
             VALUES (:reference, :status, :arrival, :departure, :name, :email, :phone, 2, 1, 30000, \'HUF\')'
        );
        $departure = (new DateTimeImmutable($arrival))->modify('+1 day')->format('Y-m-d');
        $statement->execute(compact('reference', 'status', 'arrival', 'departure', 'name', 'email', 'phone'));
        $id = (int) $this->pdo->lastInsertId();
        $this->bookingIds[] = $id;

        return $id;
    }
}
