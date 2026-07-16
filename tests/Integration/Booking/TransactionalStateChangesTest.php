<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingNotFound;
use App\Domain\Booking\BookingTransitionNotAllowed;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionalStateChangesTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<int> */
    private array $blockedIds = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $email = 'transition-' . bin2hex(random_bytes(6)) . '@example.test';
        $statement = $this->pdo->prepare(
            "INSERT INTO admins (email, password_hash, name) VALUES (:email, 'test-hash', 'Transition Test')"
        );
        $statement->execute(['email' => $email]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->bookingIds as $id) {
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type = 'booking' AND target_id = :id")
                ->execute(['id' => (string) $id]);
            $this->pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->blockedIds as $id) {
            $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testConfirmWritesStatusHistoryAuditAndContractPayloadAtomically(): void
    {
        $id = $this->booking('pending', '2042-01-10', '2042-01-13');
        $result = (new TransactionalBookingRepository($this->pdo))->transition(
            $this->reference($id), 'confirmed', $this->adminId, 'Approved internally'
        );

        self::assertSame('pending', $result->oldStatus);
        self::assertSame('confirmed', $result->newStatus);
        self::assertTrue($result->notificationQueued);
        self::assertSame('confirmed', $this->bookingStatus($id));
        $history = $this->row('booking_status_history', $id);
        self::assertSame('pending', $history['old_status']);
        self::assertSame('confirmed', $history['new_status']);
        self::assertSame('Approved internally', $history['note']);
        self::assertSame($this->adminId, (int) $history['changed_by_admin_id']);
        $auditStatement = $this->pdo->prepare(
            "SELECT * FROM audit_logs WHERE target_type = 'booking' AND target_id = :id"
        );
        $auditStatement->execute(['id' => (string) $id]);
        $audit = $auditStatement->fetch();
        self::assertSame('booking.confirmed', $audit['event_type']);
        $outbox = $this->row('email_outbox', $id);
        self::assertSame('booking_confirmed', $outbox['message_type']);
        self::assertEquals([
            'booking_reference' => $this->reference($id), 'arrival_date' => '2042-01-10',
            'departure_date' => '2042-01-13', 'adults' => 2, 'children' => 1,
            'total' => '30000.00', 'currency' => 'HUF',
        ], json_decode($outbox['payload'], true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function validTransitions(): iterable
    {
        yield 'reject' => ['pending', 'rejected', 'booking_rejected', true];
        yield 'cancel' => ['confirmed', 'cancelled', 'booking_cancelled', true];
        yield 'invalidate pending' => ['pending', 'invalidated', '', false];
        yield 'invalidate confirmed' => ['confirmed', 'invalidated', '', false];
    }

    #[DataProvider('validTransitions')]
    public function testOtherTransitions(string $from, string $to, string $messageType, bool $notifies): void
    {
        $id = $this->booking($from, '2042-02-10', '2042-02-13');
        $result = (new TransactionalBookingRepository($this->pdo))->transition(
            $this->reference($id), $to, $this->adminId
        );
        self::assertSame($to, $this->bookingStatus($id));
        self::assertSame($notifies, $result->notificationQueued);
        self::assertSame(1, $this->countRows('booking_status_history', $id));
        self::assertSame($notifies ? 1 : 0, $this->countRows('email_outbox', $id));
        if ($notifies) {
            self::assertSame($messageType, $this->row('email_outbox', $id)['message_type']);
        }
    }

    public function testForbiddenAndMissingTransitionsLeaveNoSideEffects(): void
    {
        $id = $this->booking('rejected', '2042-03-10', '2042-03-13');
        try {
            (new TransactionalBookingRepository($this->pdo))->transition(
                $this->reference($id), 'confirmed', $this->adminId
            );
            self::fail('Expected forbidden transition.');
        } catch (BookingTransitionNotAllowed) {
            self::assertSame('rejected', $this->bookingStatus($id));
            self::assertSame(0, $this->countRows('booking_status_history', $id));
            $audit = $this->pdo->prepare(
                "SELECT COUNT(*) FROM audit_logs
                 WHERE event_type = 'booking.transition_failed' AND target_type = 'booking' AND target_id = :id"
            );
            $audit->execute(['id' => (string) $id]);
            self::assertSame(1, (int) $audit->fetchColumn());
        }

        $this->expectException(BookingNotFound::class);
        (new TransactionalBookingRepository($this->pdo))->transition('MISSING', 'confirmed', $this->adminId);
    }

    public function testConfirmedAndBlockedOverlapAreRejected(): void
    {
        $first = $this->booking('confirmed', '2042-04-10', '2042-04-13');
        $candidate = $this->booking('pending', '2042-04-12', '2042-04-14');
        $repository = new TransactionalBookingRepository($this->pdo);
        try {
            $repository->transition($this->reference($candidate), 'confirmed', $this->adminId);
            self::fail('Expected confirmed conflict.');
        } catch (BookingConflict) {
            self::assertSame('pending', $this->bookingStatus($candidate));
            $failure = $this->pdo->prepare(
                "SELECT metadata_json FROM audit_logs
                 WHERE event_type = 'booking.transition_failed' AND target_id = :id ORDER BY id DESC LIMIT 1"
            );
            $failure->execute(['id' => (string) $candidate]);
            self::assertSame(
                'booking_conflict',
                json_decode((string) $failure->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)['reason_code']
            );
        }
        $this->pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = :id")->execute(['id' => $first]);
        $statement = $this->pdo->prepare(
            "INSERT INTO blocked_periods (start_date, end_date, reason) VALUES ('2042-04-11', '2042-04-15', 'test')"
        );
        $statement->execute();
        $this->blockedIds[] = (int) $this->pdo->lastInsertId();
        $this->expectException(BookingConflict::class);
        $repository->transition($this->reference($candidate), 'confirmed', $this->adminId);
    }

    public function testFailuresAfterHistoryAndAuditRollBackEverything(): void
    {
        foreach (['transition_history_inserted', 'transition_audit_inserted'] as $stage) {
            $id = $this->booking('pending', '2042-05-10', '2042-05-13');
            $repository = new TransactionalBookingRepository($this->pdo, static function (string $seen) use ($stage): void {
                if ($seen === $stage) {
                    throw new \RuntimeException('Injected transaction failure.');
                }
            });
            try {
                $repository->transition($this->reference($id), 'confirmed', $this->adminId);
                self::fail('Expected injected failure.');
            } catch (\RuntimeException $error) {
                self::assertSame('Injected transaction failure.', $error->getMessage());
            }
            self::assertSame('pending', $this->bookingStatus($id));
            self::assertSame(0, $this->countRows('booking_status_history', $id));
            self::assertSame(0, $this->countRows('email_outbox', $id));
        }
    }

    public function testParallelOverlappingConfirmationsProduceExactlyOneWinner(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the real concurrency test.');
        }
        $first = $this->booking('pending', '2042-06-10', '2042-06-14');
        $second = $this->booking('pending', '2042-06-12', '2042-06-16');
        $barrier = tempnam(sys_get_temp_dir(), 'transition-barrier-');
        self::assertIsString($barrier);
        unlink($barrier);
        $worker = __DIR__ . '/booking-transition-worker.php';
        $processes = [];
        foreach ([$first, $second] as $id) {
            $command = [PHP_BINARY, $worker, $this->reference($id), (string) $this->adminId, $barrier];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        touch($barrier);
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr ?: $stdout);
            $outputs[] = trim($stdout);
        }
        unlink($barrier);

        sort($outputs);
        self::assertSame(['CONFIRMED', 'CONFLICT'], $outputs);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE id IN (:first, :second) AND status = 'confirmed'"
        );
        $statement->execute(['first' => $first, 'second' => $second]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    private function booking(string $status, string $arrival, string $departure): int
    {
        $reference = 'TR-' . bin2hex(random_bytes(6));
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email,
                 adults, children, total_amount, currency)
             VALUES (:reference, :status, :arrival, :departure, \'Guest\', \'guest@example.test\', 2, 1, 30000, \'HUF\')'
        );
        $statement->execute(compact('reference', 'status', 'arrival', 'departure'));
        $id = (int) $this->pdo->lastInsertId();
        $this->bookingIds[] = $id;
        return $id;
    }

    private function reference(int $id): string
    {
        $statement = $this->pdo->prepare('SELECT reference FROM bookings WHERE id = :id');
        $statement->execute(['id' => $id]);
        return (string) $statement->fetchColumn();
    }

    private function bookingStatus(int $id): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM bookings WHERE id = :id');
        $statement->execute(['id' => $id]);
        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $bookingId): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM $table WHERE booking_id = :id ORDER BY id DESC LIMIT 1");
        $statement->execute(['id' => $bookingId]);
        return $statement->fetch();
    }

    private function countRows(string $table, int $bookingId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE booking_id = :id");
        $statement->execute(['id' => $bookingId]);
        return (int) $statement->fetchColumn();
    }
}
