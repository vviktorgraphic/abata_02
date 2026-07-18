<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

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
                    payload_hash, blocked_period_id, status, last_seen_at, created_at, updated_at
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
                last_seen_at = VALUES(last_seen_at)'
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
            if (!$cancelled && $row !== false && hash_equals((string) $row['payload_hash'], $payloadHash)) {
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::DUPLICATE, (int) $row['id'], $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id']);
            }

            $dates = ['start_date' => $startDate->format('Y-m-d'), 'end_date' => $endDate->format('Y-m-d')];
            if ($cancelled) {
                if ($row !== false && $row['blocked_period_id'] !== null) {
                    $inactive = $this->pdo->prepare(
                        'UPDATE blocked_periods SET is_active = FALSE, removed_at = COALESCE(removed_at, CURRENT_TIMESTAMP)
                         WHERE id = :id'
                    );
                    $inactive->execute(['id' => (int) $row['blocked_period_id']]);
                }
                $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'removed', $seenAt, $row === false || $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id']);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::REMOVED, $eventId, $row === false || $row['blocked_period_id'] === null ? null : (int) $row['blocked_period_id']);
            }

            $confirmed = $this->pdo->prepare(
                "SELECT id FROM bookings WHERE status = 'confirmed'
                 AND arrival_date < :end_date AND departure_date > :start_date LIMIT 1"
            );
            $confirmed->execute($dates);
            if ($confirmed->fetchColumn() !== false) {
                if ($row !== false && $row['blocked_period_id'] !== null) {
                    $inactive = $this->pdo->prepare(
                        'UPDATE blocked_periods SET is_active = FALSE, removed_at = COALESCE(removed_at, CURRENT_TIMESTAMP)
                         WHERE id = :id'
                    );
                    $inactive->execute(['id' => (int) $row['blocked_period_id']]);
                }
                $eventId = $this->upsert($sourceId, $externalUid, $summary, $description, $startDate, $endDate, $payloadHash, 'conflict', $seenAt);
                $this->pdo->commit();
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::CONFLICT, $eventId, null);
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
                return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::BLOCKED, $eventId, $blockedPeriodId);
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

    private function validateEvent(int $sourceId, string $externalUid, DateTimeImmutable $startDate, DateTimeImmutable $endDate, string $payloadHash, string $status): void
    {
        if ($sourceId < 1 || trim($externalUid) === '' || strlen($externalUid) > 512
            || $startDate->format('Y-m-d') >= $endDate->format('Y-m-d') || !preg_match('/^[a-f0-9]{64}$/', $payloadHash)
            || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid external calendar event.');
        }
    }
}
