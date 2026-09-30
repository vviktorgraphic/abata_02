<?php

declare(strict_types=1);

namespace App\Application\Booking;

/**
 * Durable provenance boundary for historical booking imports.
 *
 * The source file is deliberately not part of this API. Callers retain only
 * the small set of values needed for auditability and idempotency.
 */
interface LegacyImportProvenanceRepository
{
    public function createBatch(string $batchId, string $sourceSystem, ?int $adminId, \DateTimeImmutable $startedAt): int;

    /** @return array<string, mixed>|null */
    public function findBySourceBookingId(string $sourceSystem, string $sourceBookingId): ?array;

    /**
     * @param array{source_system:string, source_booking_id:string, booking_id:int,
     * source_status:string, mapped_status:string, source_calendar_id:?string,
     * source_calendar_name:?string, source_created_date:?string, source_data_hash:string,
     * source_privacy_evidence_present:bool, source_booking_policy_evidence_present:bool,
     * source_house_rules_evidence_present:bool, pricing_unavailable:bool,
     * imported_by_admin_id:?int, imported_at:string} $row
     */
    public function record(array $row, int $batchDatabaseId): int;

    /** @param array{total_rows:int, imported_rows:int, duplicate_rows:int, skipped_rows:int, invalid_rows:int} $counts */
    public function completeBatch(int $batchDatabaseId, string $status, array $counts, \DateTimeImmutable $completedAt): void;
}
