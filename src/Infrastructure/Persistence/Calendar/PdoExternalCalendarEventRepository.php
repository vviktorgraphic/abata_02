<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

use App\Domain\Booking\BookingStatus;

use App\Application\Calendar\ExternalCalendarEventRepository;
use App\Application\Calendar\ImportedEventPersistenceResult;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final readonly class PdoExternalCalendarEventRepository implements ExternalCalendarEventRepository
{
    private const STATUSES = ['imported', 'blocked', 'conflict', 'removed'];

    public function __construct(private PDO $pdo)
    {
    }

    public function findBySourceAndUid(int $sourceId, string $externalUid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, calendar_source_id, external_uid, summary, description, start_date, end_date,
                    payload_hash, blocked_period_id, status, last_seen_at, missing_since, missing_since_timestamp, created_at, updated_at
             FROM external_calendar_events WHERE calendar_source_id = :source_id AND external_uid = :external_uid'
        );
        $statement->execute(['source_id' => $sourceId, 'external_uid' => $externalUid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function upsert(int $sourceId, string $externalUid, ?string $summary, ?string $description, DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $payloadHash, string $status, DateTimeImmutable $seenAt, ?int $blockedPeriodId = null): int
    {
        if ($sourceId < 1 || trim($externalUid) === '' || strlen($externalUid) > 512
            || $startDate->format('Y-m-d') >= $endDate->format('Y-m-d') || !preg_match('/^[a-f0-9]{64}$/', $payloadHash)
            || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid external calendar event.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO external_calendar_events
                (calendar_source_id, external_uid, summary, description, start_date, end_date, payload_hash,
                 blocked_period_id, status, last_seen_at)
             VALUES (:source_id, :uid, :summary, :description, :start_date, :end_date, :payload_hash,
                     :blocked_period_id, :status, :last_seen_at)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), summary = VALUES(summary), description = VALUES(description),
                start_date = VALUES(start_date), end_date = VALUES(end_date), payload_hash = VALUES(payload_hash),
                blocked_period_id = COALESCE(VALUES(blocked_period_id), blocked_period_id), status = VALUES(status),
                last_seen_at = VALUES(last_seen_at), missing_since = NULL, missing_since_timestamp = NULL'
        );
        $statement->execute([
            'source_id' => $sourceId, 'uid' => trim($externalUid),
            'summary' => $summary === null ? null : mb_substr($summary, 0, 255), 'description' => $description,
            'start_date' => $startDate->format('Y-m-d'), 'end_date' => $endDate->format('Y-m-d'),
            'payload_hash' => $payloadHash, 'blocked_period_id' => $blockedPeriodId, 'status' => $status,
            'last_seen_at' => $seenAt->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function linkBlockedPeriod(int $eventId, int $blockedPeriodId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE external_calendar_events SET blocked_period_id = :blocked_period_id, status = 'blocked' WHERE id = :id"
        );
        $statement->execute(['blocked_period_id' => $blockedPeriodId, 'id' => $eventId]);
    }

    public function importEvent(int $sourceId, string $externalUid, ?string $summary, ?string $description, DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $payloadHash, DateTimeImmutable $seenAt, bool $cancelled = false): ImportedEventPersistenceResult
    {
        $this->validateEvent($sourceId, $externalUid, $startDate, $endDate, $payloadHash, 'imported');
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('External calendar import persistence owns its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $existing = $this->pdo->prepare(
                'SELECT id, blocked_period_id, status, payload_hash FROM external_calendar_events
                 WHERE calendar_source_id = :source_id AND external_uid = :uid FOR UPDATE'
            );
            $existing->execute(['source_id' => $sourceId, 'uid' => trim($externalUid)]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$cancelled && $row !== false && $row['status'] === 'blocked' && hash_equals((string) $row['payload_hash'], $payloadHash)) {
                $touch = $this->pdo->prepare('UPDATE external_calendar_events SET last_seen_at = :seen, missing_since = NULL, missing_since_timestamp = NULL WHERE id = :id');
                $touch->execute(['seen' => $seenAt->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s'), 'id' => (int) $row['id']]);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::DUPLICATE, (int) $row['id'], $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id']);
            }

            $dates = ['start_date' => $startDate->format('Y-m-d'), 'end_date' => $endDate->format('Y-m-d')];
            if ($cancelled) {
                $inactivated = false;
                if ($row !== false && $row['blocked_period_id'] !== null) {
                    $inactive = $this->pdo->prepare(
                        'UPDATE blocked_periods SET is_active = FALSE, removed_at = COALESCE(removed_at, CURRENT_TIMESTAMP)
                         WHERE id = :id AND is_active = TRUE'
                    );
                    $inactive->execute(['id' => (int) $row['blocked_period_id']]);
                    $inactivated = $inactive->rowCount() === 1;
                }
                $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'removed', $seenAt, $row === false || $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id']);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::REMOVED, $eventId, $row === false || $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id'], inactivated: $inactivated);
            }

            $statusPlaceholders = implode(', ', array_fill(0, count(BookingStatus::BLOCKING_VALUES), '?'));
            $blocking = $this->pdo->prepare(
                "SELECT id FROM bookings WHERE status IN ({$statusPlaceholders})
                 AND arrival_date < ? AND departure_date > ? LIMIT 1"
            );
            $blocking->execute([...BookingStatus::BLOCKING_VALUES, $dates['end_date'], $dates['start_date']]);
            if ($blocking->fetchColumn() !== false) {
                $inactivated = false;
                if ($row !== false && $row['blocked_period_id'] !== null) {
                    $inactive = $this->pdo->prepare(
                        'UPDATE blocked_periods SET is_active = FALSE, removed_at = COALESCE(removed_at, CURRENT_TIMESTAMP)
                         WHERE id = :id AND is_active = TRUE'
                    );
                    $inactive->execute(['id' => (int) $row['blocked_period_id']]);
                    $inactivated = $inactive->rowCount() === 1;
                }
                $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'conflict', $seenAt);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::CONFLICT, $eventId, null, inactivated: $inactivated);
            }

            $reason = 'Külső naptár';
            if ($summary !== null && trim($summary) !== '') {
                $reason .= ': ' . trim($summary);
            }
            if ($row !== false && $row['blocked_period_id'] !== null) {
                $blockedPeriodId = (int) $row['blocked_period_id'];
                $blocked = $this->pdo->prepare(
                    'UPDATE blocked_periods SET start_date = :start_date, end_date = :end_date, reason = :reason,
                        internal_note = :internal_note, is_active = TRUE, removed_at = NULL, removed_by_admin_id = NULL
                     WHERE id = :id'
                );
                $blocked->execute($dates + [
                    'reason' => mb_substr($reason, 0, 500),
                    'internal_note' => $description === null ? null : mb_substr($description, 0, 500),
                    'id' => $blockedPeriodId,
                ]);
                $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'blocked', $seenAt, $blockedPeriodId);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::BLOCKED, $eventId, $blockedPeriodId, true);
            }
            $blocked = $this->pdo->prepare(
                'INSERT INTO blocked_periods (start_date, end_date, reason, internal_note, is_active)
                 VALUES (:start_date, :end_date, :reason, :internal_note, TRUE)'
            );
            $blocked->execute($dates + [
                'reason' => mb_substr($reason, 0, 500),
                'internal_note' => $description === null ? null : mb_substr($description, 0, 500),
            ]);
            $blockedPeriodId = (int) $this->pdo->lastInsertId();
            $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'blocked', $seenAt, $blockedPeriodId);
            $this->pdo->commit();
            return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::BLOCKED, $eventId, $blockedPeriodId);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reconcile(int $sourceId, array $seenUids, DateTimeImmutable $now, int $graceSeconds): int
    {
        if ($graceSeconds < 86400 || $this->pdo->inTransaction()) {
            throw new \LogicException('Invalid reconciliation context.');
        }
        $now = $now->setTimezone(new DateTimeZone('Europe/Budapest'));
        $cutoff = $now->getTimestamp() - $graceSeconds;
        $seen = array_fill_keys($seenUids, true);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $query = $this->pdo->prepare("SELECT id, external_uid, blocked_period_id, missing_since_timestamp FROM external_calendar_events WHERE calendar_source_id = :source AND status <> 'removed' FOR UPDATE");
            $query->execute(['source' => $sourceId]);
            $mark = $this->pdo->prepare('UPDATE external_calendar_events SET missing_since = :at, missing_since_timestamp = :instant WHERE id = :id');
            $remove = $this->pdo->prepare("UPDATE external_calendar_events SET status = 'removed' WHERE id = :id");
            $block = $this->pdo->prepare('UPDATE blocked_periods SET is_active = FALSE, removed_at = :at WHERE id = :id AND is_active = TRUE');
            $count = 0;
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($seen[$row['external_uid']])) {
                    continue;
                }
                if ($row['missing_since_timestamp'] === null) {
                    $mark->execute(['at' => $now->format('Y-m-d H:i:s'), 'instant' => $now->getTimestamp(), 'id' => $row['id']]);
                } elseif ((int) $row['missing_since_timestamp'] <= $cutoff) {
                    if ($row['blocked_period_id'] !== null) {
                        $block->execute(['at' => $now->format('Y-m-d H:i:s'), 'id' => $row['blocked_period_id']]);
                        $count += $block->rowCount();
                    }
                    $remove->execute(['id' => $row['id']]);
                }
            }
            $this->pdo->commit();
            return $count;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function validateEvent(int $sourceId, string $externalUid, DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $payloadHash, string $status): void
    {
        if ($sourceId < 1 || trim($externalUid) === '' || strlen($externalUid) > 512
            || $startDate->format('Y-m-d') >= $endDate->format('Y-m-d') || !preg_match('/^[a-f0-9]{64}$/', $payloadHash)
            || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid external calendar event.');
        }
    }
}
