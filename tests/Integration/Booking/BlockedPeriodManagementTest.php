<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BlockedPeriodConflict;
use App\Application\Booking\BlockedPeriodNotFound;
use App\Application\Booking\BlockedPeriodService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Booking\PdoBlockedPeriodRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlockedPeriodManagementTest extends TestCase
{
    private PDO $pdo;
    private BlockedPeriodService $service;
    private int $adminId;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $repository = new PdoBlockedPeriodRepository($this->pdo, new PdoAuditLog($this->pdo));
        $this->service = new BlockedPeriodService($repository);
        $email = 'blocked-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $admin = $this->pdo->prepare('INSERT INTO admins (email, password_hash, name, is_active) VALUES (:email, :hash, :name, TRUE)');
        $admin->execute(['email' => $email, 'hash' => password_hash('integration-password', PASSWORD_DEFAULT), 'name' => 'Blocked period test']);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM audit_logs WHERE admin_id = :id')->execute(['id' => $this->adminId]);
        $this->pdo->prepare('DELETE FROM blocked_periods WHERE created_by_admin_id = :created_id OR removed_by_admin_id = :removed_id')
            ->execute(['created_id' => $this->adminId, 'removed_id' => $this->adminId]);
        $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testCreationWarnsForPendingAndRemovalUpdatesAvailabilityStateAndAudit(): void
    {
        $reference = 'BP-' . bin2hex(random_bytes(5));
        $this->booking($reference, 'pending', '2027-04-11', '2027-04-13');
        try {
            $result = $this->service->create('2027-04-10', '2027-04-12', 'Maintenance', 'Internal only', $this->adminId);
            self::assertSame([$reference], $result->overlappingPendingReferences);
            $repository = new PdoBlockedPeriodRepository($this->pdo, new PdoAuditLog($this->pdo));
            self::assertContains($result->id, array_column($repository->active(), 'id'));
            $this->service->remove($result->id, $this->adminId);
            self::assertNotContains($result->id, array_column($repository->active(), 'id'));
            $events = $this->pdo->prepare('SELECT event_type FROM audit_logs WHERE target_type = \'blocked_period\' AND target_id = :id ORDER BY id');
            $events->execute(['id' => $result->id]);
            self::assertSame(['blocked_period.created', 'blocked_period.removed'], $events->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $this->pdo->prepare('DELETE FROM bookings WHERE reference = :reference')->execute(['reference' => $reference]);
        }
    }

    public function testConfirmedOverlapRollsBackPeriodAndAudit(): void
    {
        $reference = 'BC-' . bin2hex(random_bytes(5));
        $this->booking($reference, 'confirmed', '2027-05-11', '2027-05-13');
        try {
            $before = (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn();
            try {
                $this->service->create('2027-05-10', '2027-05-12', 'Maintenance', null, $this->adminId);
                self::fail('Expected confirmed overlap conflict.');
            } catch (BlockedPeriodConflict) {
                self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM blocked_periods')->fetchColumn());
                $audit = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'blocked_period.created' AND admin_id = :admin_id");
                $audit->execute(['admin_id' => $this->adminId]);
                self::assertSame(0, (int) $audit->fetchColumn());
            }
        } finally {
            $this->pdo->prepare('DELETE FROM bookings WHERE reference = :reference')->execute(['reference' => $reference]);
        }
    }

    public function testCannotRemoveMissingOrAlreadyRemovedPeriod(): void
    {
        $result = $this->service->create('2027-06-10', '2027-06-12', 'Maintenance', null, $this->adminId);
        $this->service->remove($result->id, $this->adminId);
        $this->expectException(BlockedPeriodNotFound::class);
        $this->service->remove($result->id, $this->adminId);
    }

    private function booking(string $reference, string $status, string $arrival, string $departure): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings (reference, status, arrival_date, departure_date, guest_name, guest_email, adults)
             VALUES (:reference, :status, :arrival, :departure, \'Integration guest\', \'guest@example.invalid\', 1)'
        );
        $statement->execute(compact('reference', 'status', 'arrival', 'departure'));
    }
}
