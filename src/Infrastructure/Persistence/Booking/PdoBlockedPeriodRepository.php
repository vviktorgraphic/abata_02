<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Booking\BlockedPeriodConflict;
use App\Application\Booking\BlockedPeriodCreation;
use App\Application\Booking\BlockedPeriodManagementRepository;
use App\Application\Booking\BlockedPeriodNotFound;
use App\Domain\Booking\BlockedPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final readonly class PdoBlockedPeriodRepository implements BlockedPeriodManagementRepository
{
    public function __construct(private PDO $pdo, private AuditLog $auditLog)
    {
    }

    public function create(BlockedPeriod $period, int $adminId): BlockedPeriodCreation
    {
        if ($adminId < 1 || $this->pdo->inTransaction()) {
            throw new \LogicException('Blocked period persistence requires an admin and owns its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $confirmed = $this->pdo->prepare(
                'SELECT id FROM bookings WHERE status = \'confirmed\'
                 AND arrival_date < :end_date AND departure_date > :start_date LIMIT 1'
            );
            $dates = ['start_date' => $period->startDate->format('Y-m-d'), 'end_date' => $period->endDate->format('Y-m-d')];
            $confirmed->execute($dates);
            if ($confirmed->fetchColumn() !== false) {
                throw new BlockedPeriodConflict('The blocked period overlaps a confirmed booking.');
            }

            $pending = $this->pdo->prepare(
                'SELECT reference FROM bookings WHERE status = \'pending\'
                 AND arrival_date < :end_date AND departure_date > :start_date ORDER BY reference'
            );
            $pending->execute($dates);
            $warnings = array_map('strval', $pending->fetchAll(PDO::FETCH_COLUMN));

            $insert = $this->pdo->prepare(
                'INSERT INTO blocked_periods
                    (start_date, end_date, reason, internal_note, is_active, created_by_admin_id)
                 VALUES (:start_date, :end_date, :reason, :internal_note, TRUE, :admin_id)'
            );
            $insert->execute($dates + [
                'reason' => $period->reason,
                'internal_note' => $period->internalNote,
                'admin_id' => $adminId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auditLog->append($this->event('blocked_period.created', $id, $adminId, $dates));
            $this->pdo->commit();

            return new BlockedPeriodCreation($id, $warnings);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function remove(int $id, int $adminId): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Blocked period persistence owns its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->prepare('SELECT id FROM blocked_periods WHERE id = :id AND is_active = TRUE FOR UPDATE');
            $row->execute(['id' => $id]);
            if ($row->fetchColumn() === false) {
                throw new BlockedPeriodNotFound('Active blocked period was not found.');
            }
            $remove = $this->pdo->prepare(
                'UPDATE blocked_periods SET is_active = FALSE, removed_by_admin_id = :admin_id,
                    removed_at = :removed_at WHERE id = :id AND is_active = TRUE'
            );
            $remove->execute([
                'admin_id' => $adminId,
                'removed_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->format('Y-m-d H:i:s'),
                'id' => $id,
            ]);
            $this->auditLog->append($this->event('blocked_period.removed', $id, $adminId));
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function active(): array
    {
        return $this->pdo->query(
            'SELECT id, start_date, end_date, reason, internal_note, created_at
             FROM blocked_periods WHERE is_active = TRUE ORDER BY start_date, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, scalar|null> $extra */
    private function event(string $type, int $id, int $adminId, array $extra = []): AuditEvent
    {
        return new AuditEvent(
            $type,
            'success',
            new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type' => 'blocked_period', 'target_id' => $id] + $extra),
            $adminId,
        );
    }
}
