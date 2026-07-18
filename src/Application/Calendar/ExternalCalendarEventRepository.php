<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface ExternalCalendarEventRepository
{
    /** @return array<string, mixed>|null */
    public function findBySourceAndUid(int $sourceId, string $externalUid): ?array;

    public function upsert(
        int $sourceId,
        string $externalUid,
        ?string $summary,
        ?string $description,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $payloadHash,
        string $status,
        \DateTimeImmutable $seenAt,
        ?int $blockedPeriodId = null,
    ): int;

    public function linkBlockedPeriod(int $eventId, int $blockedPeriodId): void;

    public function importEvent(
        int $sourceId,
        string $externalUid,
        ?string $summary,
        ?string $description,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $payloadHash,
        \DateTimeImmutable $seenAt,
        bool $cancelled = false,
    ): ImportedEventPersistenceResult;
}
