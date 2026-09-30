<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\LegacyImportProvenanceRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoLegacyImportProvenanceRepository implements LegacyImportProvenanceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createBatch(string $batchId, string $sourceSystem, ?int $adminId, DateTimeImmutable $startedAt): int
    {
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $sourceSystem)
            || !preg_match('/^[0-9a-f-]{36}$/i', $batchId)) {
            throw new \InvalidArgumentException('Invalid legacy import batch identity.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO legacy_booking_import_batches
                (batch_id, source_system, imported_by_admin_id, status, started_at)
             VALUES (:batch_id, :source_system, :admin_id, \'preview\', :started_at)'
        );
        $statement->execute([
            'batch_id' => $batchId,
            'source_system' => $sourceSystem,
            'admin_id' => $adminId,
            'started_at' => $this->formatDateTime($startedAt),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findBySourceBookingId(string $sourceSystem, string $sourceBookingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, import_batch_id, source_system, source_booking_id, booking_id,
                    source_status, mapped_status, source_calendar_id, source_calendar_name,
                    source_created_date, HEX(source_data_hash) AS source_data_hash,
                    source_privacy_evidence_present, source_booking_policy_evidence_present,
                    source_house_rules_evidence_present, pricing_unavailable,
                    imported_by_admin_id, imported_at
             FROM legacy_booking_imports
             WHERE source_system = :source_system AND source_booking_id = :source_booking_id'
        );
        $statement->execute([
            'source_system' => $sourceSystem,
            'source_booking_id' => $sourceBookingId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function record(array $row, int $batchDatabaseId): int
    {
        if ($batchDatabaseId < 1 || !preg_match('/^[a-f0-9]{64}$/', $row['source_data_hash'])) {
            throw new \InvalidArgumentException('Invalid legacy import provenance.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO legacy_booking_imports
                (import_batch_id, source_system, source_booking_id, booking_id,
                 source_status, mapped_status, source_calendar_id, source_calendar_name,
                 source_created_date, source_data_hash,
                 source_privacy_evidence_present, source_booking_policy_evidence_present,
                 source_house_rules_evidence_present, pricing_unavailable,
                 imported_by_admin_id, imported_at)
             VALUES
                (:batch_id, :source_system, :source_booking_id, :booking_id,
                 :source_status, :mapped_status, :source_calendar_id, :source_calendar_name,
                 :source_created_date, UNHEX(:source_data_hash),
                 :privacy_evidence, :policy_evidence, :house_rules_evidence, :pricing_unavailable,
                 :admin_id, :imported_at)'
        );
        $statement->execute([
            'batch_id' => $batchDatabaseId,
            'source_system' => $row['source_system'],
            'source_booking_id' => $row['source_booking_id'],
            'booking_id' => $row['booking_id'],
            'source_status' => $row['source_status'],
            'mapped_status' => $row['mapped_status'],
            'source_calendar_id' => $row['source_calendar_id'],
            'source_calendar_name' => $row['source_calendar_name'],
            'source_created_date' => $row['source_created_date'],
            'source_data_hash' => $row['source_data_hash'],
            'privacy_evidence' => $row['source_privacy_evidence_present'] ? 1 : 0,
            'policy_evidence' => $row['source_booking_policy_evidence_present'] ? 1 : 0,
            'house_rules_evidence' => $row['source_house_rules_evidence_present'] ? 1 : 0,
            'pricing_unavailable' => $row['pricing_unavailable'] ? 1 : 0,
            'admin_id' => $row['imported_by_admin_id'],
            'imported_at' => $row['imported_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completeBatch(int $batchDatabaseId, string $status, array $counts, DateTimeImmutable $completedAt): void
    {
        if (!in_array($status, ['committed', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid legacy import batch status.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE legacy_booking_import_batches
             SET status = :status, total_rows = :total_rows, imported_rows = :imported_rows,
                 duplicate_rows = :duplicate_rows, skipped_rows = :skipped_rows,
                 invalid_rows = :invalid_rows, completed_at = :completed_at
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'total_rows' => $counts['total_rows'],
            'imported_rows' => $counts['imported_rows'],
            'duplicate_rows' => $counts['duplicate_rows'],
            'skipped_rows' => $counts['skipped_rows'],
            'invalid_rows' => $counts['invalid_rows'],
            'completed_at' => $this->formatDateTime($completedAt),
            'id' => $batchDatabaseId,
        ]);
    }

    private function formatDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
    }
}
