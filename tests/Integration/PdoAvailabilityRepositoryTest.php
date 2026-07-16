<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Availability\GetAvailabilityHandler;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoBlockedPeriodReadRepository;
use App\Infrastructure\Persistence\PdoBookingReadRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoAvailabilityRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }

        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 2) . '/config/database.php');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOnlyConfirmedDatabaseBookingBlocksApiCalendar(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
             VALUES (:reference, :status, :arrival, :departure, :name, :email, 1, 0)'
        );
        foreach (['confirmed', 'pending', 'cancelled'] as $index => $status) {
            $statement->execute([
                'reference' => sprintf('INTEGRATION-%s-%s', strtoupper($status), bin2hex(random_bytes(4))),
                'status' => $status,
                'arrival' => sprintf('2027-01-%02d', 10 + ($index * 4)),
                'departure' => sprintf('2027-01-%02d', 12 + ($index * 4)),
                'name' => 'Private integration name',
                'email' => 'private@example.invalid',
            ]);
        }

        $handler = new GetAvailabilityHandler(
            new PdoBookingReadRepository($this->pdo, ['confirmed']),
            new PdoBlockedPeriodReadRepository($this->pdo),
            today: $this->date('2026-07-16'),
        );
        $result = $handler->handle('2027-01-09', '2027-01-23');
        $statuses = [];
        foreach ($result['days'] as $day) {
            $statuses[$day['date']] = $day['status'];
        }

        self::assertSame('arrival_only', $statuses['2027-01-10']);
        self::assertSame('occupied', $statuses['2027-01-11']);
        self::assertSame('departure_only', $statuses['2027-01-12']);
        self::assertSame('available', $statuses['2027-01-14'], 'Pending booking must not block.');
        self::assertSame('available', $statuses['2027-01-18'], 'Cancelled booking must not block.');
        self::assertStringNotContainsString('Private integration name', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private@example.invalid', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

