<?php

declare(strict_types=1);

namespace Tests\Integration\Authentication;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\PdoAdminBookingNotificationPreferenceRepository;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoAdminBookingNotificationPreferencesTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $adminIds = [];
    /** @var array<int, bool> */
    private array $originalPreferences = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        foreach ($this->pdo->query('SELECT id, receives_booking_notifications FROM admins')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->originalPreferences[(int) $row['id']] = (bool) $row['receives_booking_notifications'];
        }
        $this->pdo->exec('UPDATE admins SET receives_booking_notifications = FALSE');
        $insert = $this->pdo->prepare(
            'INSERT INTO admins (email, password_hash, name, is_active, receives_booking_notifications)
             VALUES (:email, :hash, :name, TRUE, :receives)'
        );
        foreach ([false, true, false] as $position => $receives) {
            $insert->execute([
                'email' => 'bulk-' . bin2hex(random_bytes(6)) . '@example.invalid',
                'hash' => password_hash('integration-only-password', PASSWORD_DEFAULT),
                'name' => 'Bulk Admin ' . $position,
                'receives' => $receives ? 1 : 0,
            ]);
            $this->adminIds[] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if ($this->adminIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->adminIds), '?'));
            $deleteAudit = $this->pdo->prepare(
                "DELETE FROM audit_logs WHERE event_type LIKE 'admin_user.booking_notifications_%'
                 AND target_type = 'admin' AND target_id IN ($placeholders)"
            );
            $deleteAudit->execute(array_map('strval', $this->adminIds));
        }
        foreach ($this->adminIds as $id) {
            $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
        }
        $restore = $this->pdo->prepare('UPDATE admins SET receives_booking_notifications = :receives WHERE id = :id');
        foreach ($this->originalPreferences as $id => $receives) {
            $restore->execute(['receives' => $receives ? 1 : 0, 'id' => $id]);
        }
    }

    public function test_zero_one_and_multiple_selections_replace_all_preferences_atomically(): void
    {
        $repository = $this->repository();

        self::assertSame(
            [['id'=>$this->adminIds[1],'old'=>true,'new'=>false]],
            $repository->replaceBookingNotificationRecipients([], $this->adminIds[0], $this->now()),
        );
        self::assertSame([], $this->selected());
        self::assertSame(1, $this->auditCount());
        self::assertSame([[
            'event_type' => 'admin_user.booking_notifications_disabled',
            'admin_id' => $this->adminIds[0],
            'target_id' => (string) $this->adminIds[1],
        ]], $this->auditRows());

        self::assertSame(
            [['id'=>$this->adminIds[0],'old'=>false,'new'=>true]],
            $repository->replaceBookingNotificationRecipients([$this->adminIds[0]], $this->adminIds[0], $this->now()),
        );
        self::assertSame([$this->adminIds[0]], $this->selected());

        $changes = $repository->replaceBookingNotificationRecipients(
            [$this->adminIds[1], $this->adminIds[2]],
            $this->adminIds[0],
            $this->now(),
        );
        self::assertCount(3, $changes);
        self::assertSame([$this->adminIds[1], $this->adminIds[2]], $this->selected());
        $auditCount = $this->auditCount();
        self::assertSame([], $repository->replaceBookingNotificationRecipients(
            [$this->adminIds[1], $this->adminIds[2]],
            $this->adminIds[0],
            $this->now(),
        ));
        self::assertSame($auditCount, $this->auditCount());
    }

    public function test_unknown_selection_is_rejected_without_partial_update(): void
    {
        $repository = $this->repository();
        $before = $this->selected();
        try {
            $repository->replaceBookingNotificationRecipients(
                [$this->adminIds[0], PHP_INT_MAX],
                $this->adminIds[0],
                $this->now(),
            );
            self::fail('Unknown admin selection was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('A kijelölt felhasználó nem található.', $error->getMessage());
        }
        self::assertSame($before, $this->selected());
        self::assertSame(0, $this->auditCount());
    }

    public function test_audit_failure_rolls_back_preference_changes_and_inserted_audit_rows(): void
    {
        $realAudit = new PdoAuditLog($this->pdo);
        $failingAudit = new class($realAudit) implements AuditLog {
            private int $calls = 0;
            public function __construct(private AuditLog $inner) {}
            public function append(AuditEvent $event): void
            {
                $this->inner->append($event);
                if (++$this->calls === 1) {
                    throw new \RuntimeException('Forced audit persistence failure.');
                }
            }
        };
        $repository = $this->repository($failingAudit);
        $before = $this->selected();

        try {
            $repository->replaceBookingNotificationRecipients(
                [$this->adminIds[0], $this->adminIds[2]],
                $this->adminIds[0],
                $this->now(),
            );
            self::fail('Audit failure did not abort the preference save.');
        } catch (\RuntimeException $error) {
            self::assertSame('Forced audit persistence failure.', $error->getMessage());
        }

        self::assertSame($before, $this->selected());
        self::assertSame(0, $this->auditCount());
    }

    private function repository(?AuditLog $audit = null): PdoAdminBookingNotificationPreferenceRepository
    {
        return new PdoAdminBookingNotificationPreferenceRepository(
            $this->pdo,
            $audit ?? new PdoAuditLog($this->pdo),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'));
    }

    private function auditCount(): int
    {
        $placeholders = implode(',', array_fill(0, count($this->adminIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE event_type LIKE 'admin_user.booking_notifications_%'
             AND target_type = 'admin' AND target_id IN ($placeholders)"
        );
        $statement->execute(array_map('strval', $this->adminIds));
        return (int) $statement->fetchColumn();
    }

    /** @return list<array{event_type:string,admin_id:int,target_id:string}> */
    private function auditRows(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->adminIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT event_type, admin_id, target_id FROM audit_logs
             WHERE event_type LIKE 'admin_user.booking_notifications_%'
             AND target_type = 'admin' AND target_id IN ($placeholders) ORDER BY id"
        );
        $statement->execute(array_map('strval', $this->adminIds));
        return array_map(static fn (array $row): array => [
            'event_type' => (string) $row['event_type'],
            'admin_id' => (int) $row['admin_id'],
            'target_id' => (string) $row['target_id'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    private function selected(): array
    {
        $placeholders = implode(',', array_fill(0, count($this->adminIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM admins WHERE id IN ($placeholders) AND receives_booking_notifications = TRUE ORDER BY id"
        );
        $statement->execute($this->adminIds);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
