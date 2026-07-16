<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingPolicyCancellationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBookingPolicyCancellationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $bookingId;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
             VALUES (:reference, :status, :arrival, :departure, :name, :email, 1, 0)'
        );
        $statement->execute([
            'reference' => 'POLICY-' . strtoupper(bin2hex(random_bytes(6))),
            'status' => 'confirmed',
            'arrival' => '2040-08-10',
            'departure' => '2040-08-12',
            'name' => 'Persistence Test',
            'email' => 'persistence@example.invalid',
        ]);
        $this->bookingId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute(['id' => $this->bookingId]);
    }

    public function testPolicyAndCancellationSnapshotsAreWrittenOnce(): void
    {
        $repository = new PdoBookingPolicyCancellationRepository($this->pdo);
        self::assertTrue($repository->savePolicyAcceptance(
            $this->bookingId,
            '2040-07-01 12:00:00',
            '2026-07-16',
            '/booking-policy',
        ));
        self::assertFalse($repository->savePolicyAcceptance(
            $this->bookingId,
            '2040-07-02 12:00:00',
            'changed',
            '/changed',
        ));

        $snapshot = ['version' => 1, 'accommodation_fee' => 100000, 'penalty_amount' => 50000];
        self::assertTrue($repository->saveCancellationSnapshot(
            $this->bookingId,
            '2040-08-05 10:00:00',
            '0.5000',
            '50000.00',
            'HUF',
            1,
            $snapshot,
        ));
        self::assertFalse($repository->saveCancellationSnapshot(
            $this->bookingId,
            '2040-08-06 10:00:00',
            '0.0000',
            '0.00',
            'HUF',
            1,
            ['changed' => true],
        ));

        $statement = $this->pdo->prepare(
            'SELECT booking_policy_version, booking_policy_url, cancellation_penalty_amount,
                    cancellation_calculation_snapshot
             FROM bookings WHERE id = :id'
        );
        $statement->execute(['id' => $this->bookingId]);
        $row = $statement->fetch();
        self::assertSame('2026-07-16', $row['booking_policy_version']);
        self::assertSame('/booking-policy', $row['booking_policy_url']);
        self::assertSame('50000.00', $row['cancellation_penalty_amount']);
        self::assertEquals($snapshot, json_decode($row['cancellation_calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR));
    }
}
