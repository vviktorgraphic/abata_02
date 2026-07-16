<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingRequestOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBookingRequestOutboxTest extends TestCase
{
    private PDO $pdo;
    private int $bookingId;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $booking = $this->pdo->prepare(
            'INSERT INTO bookings
             (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
             VALUES (:reference, \'pending\', \'2042-01-10\', \'2042-01-13\', \'Guest\', :email, 2, 1)'
        );
        $reference = 'OUTBOX-' . strtoupper(bin2hex(random_bytes(5)));
        $booking->execute(['reference' => $reference, 'email' => 'outbox@example.invalid']);
        $this->bookingId = (int) $this->pdo->lastInsertId();
        $outbox = $this->pdo->prepare(
            'INSERT INTO email_outbox (booking_id, message_type, recipient, subject, payload)
             VALUES (:booking_id, \'booking_request_received\', :recipient, \'A Bata foglalási igény\', :payload)'
        );
        $outbox->execute([
            'booking_id' => $this->bookingId,
            'recipient' => 'outbox@example.invalid',
            'payload' => json_encode([
                'booking_reference' => $reference, 'arrival_date' => '2042-01-10',
                'departure_date' => '2042-01-13', 'adults' => 2, 'child_ages' => [6],
                'total' => '45000.00', 'currency' => 'HUF',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->bookingId)) {
            $statement = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $statement->execute(['id' => $this->bookingId]);
        }
    }

    public function testLoadsPayloadAndPersistsSentStatus(): void
    {
        $repository = new PdoBookingRequestOutbox($this->pdo);
        $item = $repository->findForDelivery($this->bookingId);
        self::assertNotNull($item);
        self::assertSame('processing', $this->row()['status']);
        self::assertSame('OUTBOX-', substr($item['data']->reference, 0, 7));
        self::assertSame([6], $item['data']->childAges);

        $repository->markSent($item['id']);
        self::assertSame('sent', $repository->statusForBooking($this->bookingId));
        self::assertNull($repository->findForDelivery($this->bookingId));
        $row = $this->row();
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['sent_at']);
    }

    public function testFailedStatusIsNotAutomaticallyRetriedAndReasonIsBound(): void
    {
        $repository = new PdoBookingRequestOutbox($this->pdo);
        $item = $repository->findForDelivery($this->bookingId);
        self::assertNotNull($item);
        $repository->markFailed($item['id'], 'E-mail transport failure.');

        self::assertSame('failed', $repository->statusForBooking($this->bookingId));
        self::assertNull($repository->findForDelivery($this->bookingId));
        $row = $this->row();
        self::assertSame('E-mail transport failure.', $row['last_error']);
        self::assertSame(1, (int) $row['attempts']);
    }

    public function testOnlyFirstConditionalClaimWins(): void
    {
        $first = new PdoBookingRequestOutbox($this->pdo);
        $secondPdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $second = new PdoBookingRequestOutbox($secondPdo);

        self::assertNotNull($first->findForDelivery($this->bookingId));
        self::assertNull($second->findForDelivery($this->bookingId));
        self::assertSame('pending', $second->statusForBooking($this->bookingId));
        self::assertSame('processing', $this->row()['status']);
    }

    public function testRefusesDeliveryLookupInsideDatabaseTransaction(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            (new PdoBookingRequestOutbox($this->pdo))->findForDelivery($this->bookingId);
        } finally {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        }
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $statement = $this->pdo->prepare('SELECT status, attempts, last_error, sent_at FROM email_outbox WHERE booking_id = :id');
        $statement->execute(['id' => $this->bookingId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
