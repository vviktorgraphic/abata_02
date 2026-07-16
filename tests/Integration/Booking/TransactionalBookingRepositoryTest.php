<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Booking\IdempotencyConflict;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class TransactionalBookingRepositoryTest extends TestCase
{
    private PDO $pdo;
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
    }

    protected function tearDown(): void
    {
        foreach ($this->bookingIds as $id) {
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type = 'booking' AND target_id = :id")
                ->execute(['id' => (string) $id]);
            $statement = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
        foreach ($this->blockedIds as $id) {
            $statement = $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
    }

    public function testCompleteTransactionAndIdempotentReplay(): void
    {
        $command = $this->command('2040-01-10', '2040-01-13');
        $repository = new TransactionalBookingRepository($this->pdo);

        $created = $repository->create($command, $this->pricing());
        $this->bookingIds[] = $created->bookingId;
        $replayed = $repository->create($command, $this->pricing());

        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->bookingId, $replayed->bookingId);
        self::assertSame(2, $this->countBookingRows('booking_child_ages', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_status_history', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_pricing_snapshots', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('email_outbox', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_idempotency', $created->bookingId));
        $booking = $this->pdo->prepare(
            'SELECT booking_policy_accepted_at, booking_policy_version, booking_policy_url
             FROM bookings WHERE id = :id'
        );
        $booking->execute(['id' => $created->bookingId]);
        self::assertSame([
            'booking_policy_accepted_at' => '2040-01-01 12:00:00',
            'booking_policy_version' => 'test-v1',
            'booking_policy_url' => '/booking-policy',
        ], $booking->fetch(PDO::FETCH_ASSOC));
        $audit = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE event_type = 'booking_policy.accepted' AND target_type = 'booking' AND target_id = :id"
        );
        $audit->execute(['id' => (string) $created->bookingId]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testSameKeyWithDifferentPayloadIsRejected(): void
    {
        $repository = new TransactionalBookingRepository($this->pdo);
        $first = $this->command('2040-02-10', '2040-02-13', 'stable-key');
        $created = $repository->create($first, $this->pricing());
        $this->bookingIds[] = $created->bookingId;

        $this->expectException(IdempotencyConflict::class);
        $repository->create($this->command('2040-02-11', '2040-02-14', 'stable-key'), $this->pricing());
    }

    public function testOverlappingPendingBookingsAreAccepted(): void
    {
        $repository = new TransactionalBookingRepository($this->pdo);
        $first = $repository->create($this->command('2040-03-10', '2040-03-13'), $this->pricing());
        $second = $repository->create($this->command('2040-03-11', '2040-03-14'), $this->pricing());
        $this->bookingIds[] = $first->bookingId;
        $this->bookingIds[] = $second->bookingId;

        self::assertNotSame($first->bookingId, $second->bookingId);
    }

    public function testConfirmedAndBlockedOverlapsAreRejected(): void
    {
        $confirmedId = $this->insertConfirmed('2040-04-10', '2040-04-13');
        $this->bookingIds[] = $confirmedId;
        $repository = new TransactionalBookingRepository($this->pdo);

        try {
            $repository->create($this->command('2040-04-11', '2040-04-12'), $this->pricing());
            self::fail('Confirmed overlap was accepted.');
        } catch (BookingConflict) {
            self::assertTrue(true);
        }

        $blocked = $this->pdo->prepare(
            'INSERT INTO blocked_periods (start_date, end_date, reason) VALUES (:start, :end, :reason)'
        );
        $blocked->execute(['start' => '2040-05-10', 'end' => '2040-05-13', 'reason' => 'Integration test']);
        $this->blockedIds[] = (int) $this->pdo->lastInsertId();

        $this->expectException(BookingConflict::class);
        $repository->create($this->command('2040-05-11', '2040-05-12'), $this->pricing());
    }

    public function testChildFailureRollsBackEveryRecordIncludingClaim(): void
    {
        $command = new BookingPersistenceCommand(
            'rollback-' . bin2hex(random_bytes(8)),
            hash('sha256', 'rollback-payload-' . random_bytes(4)),
            'ROLLBACK-' . strtoupper(bin2hex(random_bytes(5))),
            '2040-06-10',
            '2040-06-13',
            'Integration Guest',
            'integration@example.invalid',
            '+3612345678',
            2,
            [6, 99],
            null,
            '2040-01-01 12:00:00',
            'test-v1',
            '/booking-policy',
        );

        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, $this->pricing());
            self::fail('Invalid child age did not fail.');
        } catch (\PDOException) {
            $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
            $booking->execute(['reference' => $command->reference]);
            self::assertSame(0, (int) $booking->fetchColumn());
            $claim = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)'
            );
            $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
            self::assertSame(0, (int) $claim->fetchColumn());
        }
    }

    /** @dataProvider transactionalFailureStages */
    public function testFailureAfterBookingOrHistoryInsertRollsBackEveryPartialRecord(string $stage): void
    {
        $command = $this->command('2042-01-10', '2042-01-13');
        $repository = new TransactionalBookingRepository($this->pdo, static function (string $current) use ($stage): void {
            if ($current === $stage) {
                throw new \RuntimeException('Injected transaction failure.');
            }
        });

        try {
            $repository->create($command, $this->pricing());
            self::fail('Injected transaction failure was not raised.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected transaction failure.', $error->getMessage());
        }

        $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
        $booking->execute(['reference' => $command->reference]);
        self::assertSame(0, (int) $booking->fetchColumn());
        $claim = $this->pdo->prepare('SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)');
        $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
        self::assertSame(0, (int) $claim->fetchColumn());
    }

    /** @return iterable<string, array{string}> */
    public static function transactionalFailureStages(): iterable
    {
        yield 'after booking insert' => ['booking_inserted'];
        yield 'after policy audit insert' => ['policy_audit_inserted'];
        yield 'after status history insert' => ['history_inserted'];
    }

    public function testSnapshotSerializationFailureRollsBackBookingHistoryAndChildren(): void
    {
        $command = $this->command('2040-07-10', '2040-07-13');
        $invalidSnapshot = new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                return new BookingPricing('30000.00', 'HUF', [
                    'version' => 1,
                    'invalid_utf8' => "\xB1\x31",
                ]);
            }
        };

        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, $invalidSnapshot);
            self::fail('Invalid snapshot did not fail.');
        } catch (\JsonException) {
            $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
            $booking->execute(['reference' => $command->reference]);
            self::assertSame(0, (int) $booking->fetchColumn());

            $claim = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)'
            );
            $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
            self::assertSame(0, (int) $claim->fetchColumn());
        }
    }

    public function testConcurrentIdempotentRequestsCreateOneBookingAndReturnSameResult(): void
    {
        $key = 'concurrent-key-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', 'identical-concurrent-payload');
        $reference = 'RACE-' . strtoupper(bin2hex(random_bytes(5)));
        $first = $this->startWorker($key, $hash, $reference, '2041-01-10', '2041-01-13');
        $second = $this->startWorker($key, $hash, $reference, '2041-01-10', '2041-01-13');

        $firstResult = $this->finishWorker($first);
        $secondResult = $this->finishWorker($second);

        self::assertTrue($firstResult['ok']);
        self::assertTrue($secondResult['ok']);
        self::assertSame($firstResult['booking_id'], $secondResult['booking_id']);
        self::assertNotSame($firstResult['replayed'], $secondResult['replayed']);
        $this->bookingIds[] = (int) $firstResult['booking_id'];
    }

    public function testInventoryLockMakesConcurrentCreateObserveNewConfirmedBooking(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
        $worker = $this->startWorker(
            'confirmed-race-' . bin2hex(random_bytes(8)), hash('sha256', 'confirmed-race-payload'),
            'CONFIRMED-RACE-' . strtoupper(bin2hex(random_bytes(5))), '2041-02-10', '2041-02-13',
        );

        usleep(200_000);
        $confirmedId = $this->insertConfirmed('2041-02-11', '2041-02-12');
        $this->bookingIds[] = $confirmedId;
        $this->pdo->commit();

        $result = $this->finishWorker($worker);
        self::assertFalse($result['ok']);
        self::assertSame(BookingConflict::class, $result['exception']);
    }

    private function command(string $arrival, string $departure, ?string $key = null): BookingPersistenceCommand
    {
        $key ??= 'key-' . bin2hex(random_bytes(10));
        $payload = implode('|', [$arrival, $departure, $key]);

        return new BookingPersistenceCommand(
            $key,
            hash('sha256', $payload),
            'PUBLIC-' . strtoupper(bin2hex(random_bytes(6))),
            $arrival,
            $departure,
            'Integration Guest',
            'integration@example.invalid',
            '+3612345678',
            2,
            [6, 9],
            'Integration note',
            '2040-01-01 12:00:00',
            'test-v1',
            '/booking-policy',
        );
    }

    private function pricing(): BookingPricingProvider
    {
        return new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                return new BookingPricing('30000.00', 'HUF', [
                    'version' => 1,
                    'arrival_date' => $command->arrivalDate,
                    'departure_date' => $command->departureDate,
                    'total' => 30000,
                    'currency' => 'HUF',
                ]);
            }
        };
    }

    private function countBookingRows(string $table, int $bookingId): int
    {
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE booking_id = :id', $table));
        $statement->execute(['id' => $bookingId]);

        return (int) $statement->fetchColumn();
    }

    private function insertConfirmed(string $arrival, string $departure): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
             VALUES (:reference, \'confirmed\', :arrival, :departure, :name, :email, 1, 0)'
        );
        $statement->execute([
            'reference' => 'CONFIRMED-' . strtoupper(bin2hex(random_bytes(5))),
            'arrival' => $arrival,
            'departure' => $departure,
            'name' => 'Integration Guest',
            'email' => 'integration@example.invalid',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{process: resource, pipes: array<int, resource>, output: string} */
    private function startWorker(string $key, string $hash, string $reference, string $arrival, string $departure): array
    {
        $output = tempnam(sys_get_temp_dir(), 'booking-race-');
        if ($output === false) {
            throw new \RuntimeException('Unable to allocate concurrency test output.');
        }
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, __DIR__ . '/booking-create-worker.php', $output, $key, $hash, $reference, $arrival, $departure,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($output);
            throw new \RuntimeException('Unable to start concurrency test worker.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes, 'output' => $output];
    }

    /** @param array{process: resource, pipes: array<int, resource>, output: string} $worker */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $json = file_get_contents($worker['output']);
        @unlink($worker['output']);
        self::assertSame(0, $exitCode, trim((string) $stdout . "\n" . (string) $stderr));
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
